<?php

namespace App\Http\Controllers;

use App\Models\OnsiteInterview;
use App\Models\StudyProgram;
use App\Models\SurveyAssignment;
use App\Services\OnsiteInterviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OnsiteInterviewController extends Controller
{
    /** @var array<string, string> */
    public const FILTERS = ['belum_selesai' => 'Belum selesai', 'tersedia' => 'Belum diambil', 'saya' => 'Tugas saya', 'selesai' => 'Selesai', 'semua' => 'Semua'];

    /**
     * Daftar wawancara onsite; bawaannya hanya yang belum selesai, tugas petugas sendiri di urutan teratas.
     */
    public function index(Request $r): View
    {
        abort_unless($r->user()->canAny(['surveys.work', 'surveys.view-all']), 403);
        $filters = $r->validate(['status' => ['nullable', Rule::in(array_keys(self::FILTERS))], 'q' => 'nullable|string|max:100', 'program' => 'nullable|integer']);
        $status = $filters['status'] ?? 'belum_selesai';
        $interviews = OnsiteInterview::with(['student.program', 'claimer', 'assignment.survey', 'assignment.latestFailure', 'assignment.user'])
            ->when($filters['q'] ?? null, fn ($query, string $q) => $query->whereHas('student', fn ($student) => $student->where(fn ($s) => $s->where('name', 'like', "%$q%")->orWhere('nim', 'like', "%$q%"))))
            ->when($filters['program'] ?? null, fn ($query, int $program) => $query->whereHas('student', fn ($student) => $student->where('study_program_id', $program)))
            ->get();
        $others = SurveyAssignment::with('survey', 'user')->whereIn('student_id', $interviews->whereNull('claimed_by')->pluck('student_id'))->get()
            ->keyBy(fn (SurveyAssignment $a): string => $a->student_id.'-'.$a->survey_period_id);
        $interviews->whereNull('claimed_by')->each(fn (OnsiteInterview $i) => $i->setOtherAssignment($others->get($i->student_id.'-'.$i->survey_period_id)));

        $userId = $r->user()->id;
        $matches = fn (OnsiteInterview $i, string $filter): bool => match ($filter) {
            'belum_selesai' => $i->status() !== 'selesai',
            'tersedia' => $i->status() === 'tersedia',
            'saya' => $i->claimed_by === $userId && $i->status() !== 'selesai',
            'selesai' => $i->status() === 'selesai',
            'semua' => true,
        };
        $rows = $interviews->filter(fn (OnsiteInterview $i): bool => $matches($i, $status))
            ->sortBy(fn (OnsiteInterview $i): string => match (true) {
                $i->claimed_by === $userId => '0',
                $i->status() === 'tersedia' => '1',
                default => '2',
            }.mb_strtolower($i->student->name))
            ->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('onsite', [
            'interviews' => new LengthAwarePaginator($rows->forPage($page, 30)->values(), $rows->count(), 30, $page, ['path' => $r->url(), 'query' => $r->query()]),
            'counts' => collect(self::FILTERS)->map(fn (string $label, string $key): int => $interviews->filter(fn (OnsiteInterview $i): bool => $matches($i, $key))->count()),
            'tabs' => self::FILTERS,
            'status' => $status,
            'filters' => $filters,
            'programs' => StudyProgram::whereIn('id', $interviews->pluck('student.study_program_id')->unique())->orderBy('name')->get(),
        ]);
    }

    public function claim(Request $r, OnsiteInterview $interview, OnsiteInterviewService $service): RedirectResponse
    {
        return $this->attempt(fn () => $service->claim($interview, $r->user()), $interview->student->name.' diambil untuk wawancara. Tekan Mulai wawancara saat mahasiswa hadir.');
    }

    public function release(Request $r, OnsiteInterview $interview, OnsiteInterviewService $service): RedirectResponse
    {
        return $this->attempt(fn () => $service->release($interview, $r->user()), $interview->student->name.' dikembalikan ke daftar wawancara.');
    }

    /**
     * Penolakan karena kalah cepat atau wawancara sudah dimulai ditampilkan sebagai pesan, bukan halaman galat.
     */
    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (HttpException $e) {
            abort_if($e->getStatusCode() === 403, 403);

            return back()->withErrors(['onsite' => $e->getMessage()]);
        }

        return back()->with('status', $success);
    }
}
