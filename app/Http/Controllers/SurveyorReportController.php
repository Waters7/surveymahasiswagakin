<?php

namespace App\Http\Controllers;

use App\Exports\SurveyExport;
use App\Models\Survey;
use App\Models\SurveyPeriod;
use App\Models\User;
use App\Services\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SurveyorReportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $allSurveys = $this->query($request->user(), $filters)->with('user', 'assignment.period')->get();
        $summaries = $allSurveys->groupBy('user_id')->map(fn (Collection $surveys): array => [
            'user' => $surveys->first()->user,
            'ready' => $surveys->filter(fn (Survey $survey): bool => $survey->locked_at !== null && $survey->submitted_at !== null)->count(),
            'unfinished' => $surveys->filter(fn (Survey $survey): bool => $survey->locked_at === null || $survey->submitted_at === null)->count(),
        ])->sortBy('user.name')->values();
        $surveys = $this->query($request->user(), $filters)->with('user', 'assignment.period')->latest('started_at')->paginate(20)->withQueryString();
        $surveyorQuery = User::where(fn (Builder $query): Builder => $query->whereHas('assignments')->orWhereHas('surveys'));
        if (! $request->user()->can('surveys.view-all')) {
            $surveyorQuery->whereKey($request->user()->id);
        }
        $surveyors = $surveyorQuery->orderBy('name')->get();
        $periods = SurveyPeriod::orderByDesc('starts_at')->get();

        return view('reports.index', compact('filters', 'summaries', 'surveys', 'surveyors', 'periods'));
    }

    public function print(Request $request): Response
    {
        $filters = $this->filters($request);
        $surveys = $this->query($request->user(), $filters)
            ->whereNotNull('locked_at')->whereNotNull('submitted_at')
            ->with('answers', 'signatures', 'photos', 'location', 'user', 'assignment.period')
            ->orderBy('user_id')->orderBy('submitted_at')->orderBy('id')->get();
        abort_if($surveys->isEmpty(), 422, 'Belum ada hasil survey terkirim yang dapat dicetak pada filter ini.');
        $documents = $surveys->map(function (Survey $survey): array {
            Gate::authorize('view', $survey);
            $qr = (new PngWriter)->write(new QrCode(data: url('/verify/'.$survey->verify_token), size: 140, margin: 5))->getDataUri();
            $signatures = $survey->signatures->mapWithKeys(fn ($signature): array => [$signature->role => [
                'name' => $signature->signed_by,
                'image' => 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($signature->path)),
            ]]);
            $photos = $survey->photos->map(fn ($photo): array => [
                'caption' => $photo->label(),
                'image' => $photo->isPdf() ? null : 'data:image/jpeg;base64,'.base64_encode(Storage::disk('local')->get($photo->path)),
                'url' => url('/media/'.$photo->uuid),
                'filename' => $photo->metadata['original_name'] ?? 'dokumen.pdf',
            ]);

            return compact('survey', 'qr', 'signatures', 'photos');
        });
        $period = isset($filters['survey_period_id']) ? SurveyPeriod::find($filters['survey_period_id']) : null;
        $printedAt = now()->timezone('Asia/Makassar');
        $response = Pdf::loadView('reports.surveyor-bundle', compact('documents', 'period', 'printedAt', 'filters'))
            ->setPaper('a4')->setOption('isRemoteEnabled', false)
            ->stream('laporan-survey-gakin-'.$printedAt->format('Ymd').'.pdf');
        Audit::record('report.surveyor-pdf', 'surveys', [], ['count' => $surveys->count(), 'survey_uuids' => $surveys->pluck('uuid')->all(), 'filters' => $filters]);

        return $response->header('Cache-Control', 'private, no-store');
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        $surveys = $this->query($request->user(), $filters)
            ->whereNotNull('locked_at')->whereNotNull('submitted_at')
            ->with('answers', 'photos', 'user', 'assignment.period')
            ->orderBy('user_id')->orderBy('submitted_at')->orderBy('id')->get();
        abort_if($surveys->isEmpty(), 422, 'Belum ada hasil survey terkirim yang dapat diekspor pada filter ini.');
        $criterionCount = $surveys->max(fn (Survey $survey): int => count($survey->criteria_snapshot));
        $columns = ['No', 'ID survey', 'Nomor pendaftaran', 'NIM', 'Nama mahasiswa', 'Semester', 'Program studi', 'HP', 'Alamat', 'Kabupaten/Kota', 'Surveyor', 'NIP surveyor', 'Periode survey', 'Mulai survey (WITA)', 'Terkirim (WITA)'];
        for ($position = 1; $position <= $criterionCount; $position++) {
            $columns = array_merge($columns, ['Kriteria '.$position, 'Jawaban '.$position, 'Rincian '.$position, 'Catatan '.$position]);
        }
        $columns = array_merge($columns, ['PKH', 'Jumlah bukti PKH', 'Sesuai', 'Tidak sesuai', 'Perlu verifikasi', 'Kesimpulan', 'Rekomendasi', 'Status verifikasi', 'Metode survey']);
        $rows = [];
        foreach ($surveys as $index => $survey) {
            Gate::authorize('view', $survey);
            $student = $survey->student_snapshot;
            $row = [$index + 1, $survey->uuid, $student['registration_number'] ?? '', $student['nim'] ?? '', $student['name'] ?? '', $student['semester'] ?? '', $student['program']['name'] ?? '', $student['phone'] ?? '', $student['address']['address'] ?? '', $student['address']['city'] ?? '', $survey->user->name, $survey->user->nip, $survey->assignment?->period?->name, $survey->started_at?->timezone('Asia/Makassar')->format('Y-m-d H:i:s'), $survey->submitted_at->timezone('Asia/Makassar')->format('Y-m-d H:i:s')];
            $criteria = collect($survey->criteria_snapshot)->sortBy('position')->values();
            for ($position = 0; $position < $criterionCount; $position++) {
                $criterion = $criteria->get($position);
                $answer = $criterion ? $survey->answers->firstWhere('criterion_id', $criterion['id']) : null;
                $row = array_merge($row, [$criterion['label'] ?? '', str_replace('_', ' ', $answer?->answer ?? ''), $answer?->detail ?? '', $answer?->notes ?? '']);
            }
            $rows[] = array_merge($row, [$survey->pkh === null ? '' : ($survey->pkh ? 'Ya' : 'Tidak'), $survey->photos->where('kind', 'pkh')->count(), $survey->answers->where('answer', 'ya')->count(), $survey->answers->where('answer', 'tidak')->count(), $survey->answers->where('answer', 'perlu_verifikasi')->count(), $survey->conclusion, str_replace('_', ' ', $survey->recommendation ?? ''), str_replace('_', ' ', $survey->status), $survey->method?->label()]);
        }
        $response = Excel::download(new SurveyExport($rows, $columns), 'hasil-survey-gakin.xlsx', null, ['Cache-Control' => 'private, no-store']);
        Audit::record('report.surveyor-excel', 'surveys', [], ['count' => $surveys->count(), 'survey_uuids' => $surveys->pluck('uuid')->all(), 'filters' => $filters]);

        return $response;
    }

    /** @return array{user_id?: int|string|null, survey_period_id?: int|string|null, date_from?: string|null, date_to?: string|null} */
    private function filters(Request $request): array
    {
        abort_unless($request->user()->can('surveys.view-all') || $request->user()->can('surveys.work'), 403);
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'survey_period_id' => ['nullable', 'integer', 'exists:survey_periods,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
        ], [
            'date_from.date_format' => 'Tanggal mulai harus berupa tanggal yang valid (YYYY-MM-DD).',
            'date_to.date_format' => 'Tanggal akhir harus berupa tanggal yang valid (YYYY-MM-DD).',
            'date_to.after_or_equal' => 'Tanggal akhir harus sama dengan atau setelah tanggal mulai.',
        ]);
        if (! $request->user()->can('surveys.view-all')) {
            abort_if(isset($filters['user_id']) && (int) $filters['user_id'] !== $request->user()->id, 403);
            $filters['user_id'] = $request->user()->id;
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array{user_id?: int|string|null, survey_period_id?: int|string|null, date_from?: string|null, date_to?: string|null}  $filters
     * @return Builder<Survey>
     */
    private function query(User $user, array $filters): Builder
    {
        $query = Survey::query();
        if (! $user->can('surveys.view-all')) {
            $query->where('user_id', $user->id);
        }
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (isset($filters['survey_period_id'])) {
            $query->whereHas('assignment', fn (Builder $assignment): Builder => $assignment->where('survey_period_id', $filters['survey_period_id']));
        }

        if (isset($filters['date_from'])) {
            $query->where('started_at', '>=', CarbonImmutable::parse($filters['date_from'], 'Asia/Makassar')->startOfDay()->timezone(config('app.timezone')));
        }
        if (isset($filters['date_to'])) {
            $query->where('started_at', '<', CarbonImmutable::parse($filters['date_to'], 'Asia/Makassar')->addDay()->startOfDay()->timezone(config('app.timezone')));
        }

        return $query;
    }
}
