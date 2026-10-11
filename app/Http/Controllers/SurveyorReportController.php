<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyPeriod;
use App\Models\User;
use App\Services\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

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
        $response = Pdf::loadView('reports.surveyor-bundle', compact('documents', 'period', 'printedAt'))
            ->setPaper('a4')->setOption('isRemoteEnabled', false)
            ->stream('laporan-survey-gakin-'.$printedAt->format('Ymd').'.pdf');
        Audit::record('report.surveyor-pdf', 'surveys', [], ['count' => $surveys->count(), 'survey_uuids' => $surveys->pluck('uuid')->all(), 'filters' => $filters]);

        return $response->header('Cache-Control', 'private, no-store');
    }

    /** @return array{user_id?: int|string|null, survey_period_id?: int|string|null} */
    private function filters(Request $request): array
    {
        abort_unless($request->user()->can('surveys.view-all') || $request->user()->can('surveys.work'), 403);
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'survey_period_id' => ['nullable', 'integer', 'exists:survey_periods,id'],
        ]);
        if (! $request->user()->can('surveys.view-all')) {
            abort_if(isset($filters['user_id']) && (int) $filters['user_id'] !== $request->user()->id, 403);
            $filters['user_id'] = $request->user()->id;
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array{user_id?: int|string|null, survey_period_id?: int|string|null}  $filters
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

        return $query;
    }
}
