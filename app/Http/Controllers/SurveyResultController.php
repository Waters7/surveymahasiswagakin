<?php

namespace App\Http\Controllers;

use App\Enums\SurveyMethod;
use App\Models\StudentAddress;
use App\Models\StudyProgram;
use App\Models\SurveyAssignment;
use App\Models\SurveyPeriod;
use App\Services\AssignmentQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SurveyResultController extends Controller
{
    /**
     * Rekap hasil keputusan survey per mahasiswa, prodi, dan wilayah sesuai cakupan akses pengguna.
     */
    public function index(Request $r): View
    {
        $filters = $r->validate([
            'q' => 'nullable|string|max:100',
            'program' => 'nullable|integer',
            'city' => 'nullable|string|max:150',
            'survey_period_id' => 'nullable|integer',
            'method' => ['nullable', Rule::enum(SurveyMethod::class)],
            'result' => ['nullable', Rule::in(array_keys(SurveyAssignment::RESULT_LABELS))],
        ]);
        $assignments = AssignmentQuery::filtered($r->user(), collect($filters)->except('result')->all())
            ->with(['survey' => fn ($survey) => $survey->withCount(['answers', 'answers as fulfilled_count' => fn ($answer) => $answer->where('answer', 'ya')])])
            ->get();
        $countByResult = fn (Collection $rows): Collection => collect(SurveyAssignment::RESULT_LABELS)->map(fn (string $label, string $key): int => $rows->filter(fn (SurveyAssignment $a): bool => $a->resultCategory() === $key)->count());
        $breakdown = fn (callable $group): Collection => $assignments->groupBy($group)->map(fn (Collection $rows): Collection => $countByResult($rows)->put('total', $rows->count()))->sortKeys();

        $order = array_flip(array_keys(SurveyAssignment::RESULT_LABELS));
        $rows = $assignments
            ->when($filters['result'] ?? null, fn (Collection $all, string $result): Collection => $all->filter(fn (SurveyAssignment $a): bool => $a->resultCategory() === $result))
            ->sortBy(fn (SurveyAssignment $a): string => $order[$a->resultCategory()].'-'.mb_strtolower($a->student->name))
            ->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('results', [
            'assignments' => new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, ['path' => $r->url(), 'query' => $r->query()]),
            'summary' => $countByResult($assignments),
            'total' => $assignments->count(),
            'byProgram' => $breakdown(fn (SurveyAssignment $a): string => $a->student->program->name),
            'byCity' => $breakdown(fn (SurveyAssignment $a): string => $a->student->address?->city ?: 'Belum diisi'),
            'filters' => $filters,
            'labels' => SurveyAssignment::RESULT_LABELS,
            'programs' => StudyProgram::orderBy('name')->get(),
            'periods' => SurveyPeriod::all(),
            'cities' => StudentAddress::whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
        ]);
    }
}
