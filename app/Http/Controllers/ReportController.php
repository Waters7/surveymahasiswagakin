<?php

namespace App\Http\Controllers;

use App\Exports\SurveyExport;
use App\Models\Survey;
use App\Services\AssignmentQuery;
use App\Services\Audit;
use App\Services\SurveyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function visits(Request $r): View
    {
        $assignments = AssignmentQuery::filtered($r->user(), $r->all())->whereHas('survey')->get()->sortBy(fn ($a) => $a->survey->started_at);

        return view('reports.visits', compact('assignments'));
    }

    public function excel(Request $r): BinaryFileResponse
    {
        $all = AssignmentQuery::filtered($r->user(), $r->all())->get();
        $max = max(10, $all->max(fn ($a) => count($a->survey?->criteria_snapshot ?? [])) ?? 0);
        $columns = ['No', 'Nama', 'NIM', 'Semester', 'Prodi', 'HP', 'Alamat', 'Kabupaten/Kota', 'Surveyor', 'Tanggal survey'];
        for ($i = 1; $i <= $max; $i++) {
            $columns[] = 'Kriteria '.$i;
        }
        $columns = array_merge($columns, ['PKH', 'Sesuai', 'Tidak sesuai', 'Perlu verifikasi', 'Kesimpulan', 'Rekomendasi', 'Status verifikasi']);
        $rows = [];
        foreach ($all as $i => $a) {
            $s = $a->survey;
            $row = [$i + 1, $a->student->name, $a->student->nim, $a->student->semester, $a->student->program->name, $a->student->phone, $a->student->address?->address, $a->student->address?->city, $a->user->name, $s?->submitted_at?->format('Y-m-d')];
            for ($j = 0; $j < $max; $j++) {
                $row[] = $s?->answers->firstWhere('criterion_id', $s?->criteria_snapshot[$j]['id'] ?? null)?->answer ?? '';
            }
            $rows[] = array_merge($row, [$s?->pkh === null ? '' : ($s->pkh ? 'Ya' : 'Tidak'), $s?->answers->where('answer', 'ya')->count() ?? 0, $s?->answers->where('answer', 'tidak')->count() ?? 0, $s?->answers->where('answer', 'perlu_verifikasi')->count() ?? 0, $s?->conclusion, $s?->recommendation, $s?->status ?? $a->status]);
        }
        Audit::record('report.excel', 'assignments', [], ['count' => count($rows)]);

        return Excel::download(new SurveyExport($rows, $columns), 'rekap-survey-gakin.xlsx');
    }

    public function pdf(Survey $survey): Response
    {
        Gate::authorize('view', $survey);
        abort_unless($survey->locked_at, 422, 'PDF tersedia setelah submit.');
        $survey->load('answers', 'signatures', 'photos', 'location', 'user');
        $qr = (new PngWriter)->write(new QrCode(data: url('/verify/'.$survey->verify_token), size: 140, margin: 5))->getDataUri();
        $signatures = $survey->signatures->mapWithKeys(fn ($s) => [$s->role => ['name' => $s->signed_by, 'image' => 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($s->path))]]);
        $photos = $survey->photos->map(fn ($p) => ['caption' => $p->kind === 'group' ? 'Foto bersama' : 'Bukti kriteria '.$p->criterion_id, 'image' => 'data:image/jpeg;base64,'.base64_encode(Storage::disk('local')->get($p->path))]);
        Audit::record('report.pdf', $survey);

        return Pdf::loadView('reports.survey', compact('survey', 'qr', 'signatures', 'photos'))->setPaper('a4')->setOption('isRemoteEnabled', false)->stream('survey-'.$survey->uuid.'.pdf');
    }

    public function verify(string $token, SurveyService $service): Response
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/', $token), 404);
        $s = Survey::where('verify_token', $token)->whereNotNull('locked_at')->first();
        $valid = $s && hash_equals($s->document_hash, $service->digest($s));
        $public = $s ? ['document_number' => $s->settings_snapshot['document_number'].'/'.$s->uuid, 'name' => $s->student_snapshot['name'], 'date' => $s->submitted_at->format('d M Y'), 'surveyor' => $s->user->name, 'status' => str_replace('_', ' ', $s->status)] : null;

        return response()->view('reports.verify', compact('valid', 'public'))->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
