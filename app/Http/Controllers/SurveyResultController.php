<?php

namespace App\Http\Controllers;

use App\Enums\Recommendation;
use App\Enums\SurveyMethod;
use App\Models\StudentAddress;
use App\Models\StudyProgram;
use App\Models\SurveyAssignment;
use App\Models\SurveyPeriod;
use App\Services\AssignmentQuery;
use App\Services\SurveyResultInsights;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SurveyResultController extends Controller
{
    /** @var array<string, string> */
    public const STUDENT_FILTERS = [
        'direkomendasikan' => 'Direkomendasikan',
        'terverifikasi' => 'Sudah diverifikasi',
        'belum_verifikasi' => 'Belum diverifikasi',
        'tidak_direkomendasikan' => 'Tidak direkomendasikan',
        'belum_dikirim' => 'Belum dikirim',
    ];

    /**
     * Dashboard hasil: angka utama, komposisi keputusan, rincian, kriteria, dan temuan.
     */
    public function index(Request $r, SurveyResultInsights $insights): View
    {
        $filters = $this->filters($r);

        return view('results', $insights->summarize($this->assignments($r, $filters)) + $this->options($filters));
    }

    /**
     * Data mahasiswa survey dengan pilihan cepat status verifikasi dan rekomendasi.
     */
    public function students(Request $r): View
    {
        $filters = $this->filters($r) + $r->validate([
            'status' => ['nullable', Rule::in(array_keys(self::STUDENT_FILTERS))],
            'recommendation' => ['nullable', Rule::enum(Recommendation::class)],
        ]);
        $assignments = $this->assignments($r, $filters);
        $counts = collect(self::STUDENT_FILTERS)->map(fn (string $label, string $key): int => $assignments->filter(fn (SurveyAssignment $a): bool => $this->matches($a, $key))->count());
        $order = array_flip(array_keys(SurveyAssignment::RESULT_LABELS));
        $rows = $assignments
            ->when($filters['status'] ?? null, fn (Collection $all, string $status): Collection => $all->filter(fn (SurveyAssignment $a): bool => $this->matches($a, $status)))
            ->sortBy(fn (SurveyAssignment $a): string => $order[$a->resultCategory()].'-'.mb_strtolower($a->student->name))
            ->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('results-students', [
            'assignments' => new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, ['path' => $r->url(), 'query' => $r->query()]),
            'total' => $assignments->count(),
            'counts' => $counts,
            'statusFilters' => self::STUDENT_FILTERS,
        ] + $this->options($filters));
    }

    private function matches(SurveyAssignment $a, string $filter): bool
    {
        $status = $a->survey?->status;

        return match ($filter) {
            'direkomendasikan', 'tidak_direkomendasikan' => $a->resultCategory() === $filter,
            'terverifikasi' => in_array($status, ['disetujui', 'tidak_direkomendasikan'], true),
            'belum_verifikasi' => in_array($status, ['menunggu_verifikasi', 'perlu_revisi'], true),
            'belum_dikirim' => $a->survey?->submitted_at === null,
        };
    }

    /**
     * @return array{q?: string, program?: int, city?: string, survey_period_id?: int, method?: string}
     */
    private function filters(Request $r): array
    {
        return $r->validate([
            'q' => 'nullable|string|max:100',
            'program' => 'nullable|integer',
            'city' => 'nullable|string|max:150',
            'survey_period_id' => 'nullable|integer',
            'method' => ['nullable', Rule::enum(SurveyMethod::class)],
        ]);
    }

    /**
     * Penugasan sesuai filter dan cakupan akses; filter status dihitung setelah data dimuat.
     *
     * @return Collection<int, SurveyAssignment>
     */
    private function assignments(Request $r, array $filters): Collection
    {
        return AssignmentQuery::filtered($r->user(), collect($filters)->except('status')->all())
            ->with(['survey' => fn ($survey) => $survey->withCount(['answers', 'answers as fulfilled_count' => fn ($answer) => $answer->where('answer', 'ya')])->with('latestVerification')])
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(array $filters): array
    {
        return [
            'filters' => $filters,
            'labels' => SurveyAssignment::RESULT_LABELS,
            'programs' => StudyProgram::orderBy('name')->get(),
            'periods' => SurveyPeriod::all(),
            'cities' => StudentAddress::whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
        ];
    }
}
