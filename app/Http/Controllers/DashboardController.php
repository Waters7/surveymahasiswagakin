<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Student;
use App\Models\StudentAddress;
use App\Models\StudyProgram;
use App\Models\SurveyPeriod;
use App\Models\User;
use App\Services\AssignmentQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $r): View
    {
        $q = AssignmentQuery::filtered($r->user());
        $all = $q->get();
        $done = $all->filter(fn ($a) => $a->survey?->locked_at)->count();
        $kpis = ['target' => $r->user()->can('surveys.view-all') ? Student::count() : $all->count(), 'assigned' => $all->count(), 'in_progress' => $all->filter(fn ($a) => $a->survey && ! $a->survey->locked_at)->count(), 'recommended' => $all->filter(fn ($a) => $a->survey?->recommendation === 'direkomendasikan' && $a->survey?->status === 'disetujui')->count(), 'not_recommended' => $all->filter(fn ($a) => $a->survey?->status === 'tidak_direkomendasikan' || ($a->survey?->recommendation === 'tidak_direkomendasikan' && $a->survey?->status === 'disetujui'))->count(), 'done' => $done, 'pending' => $all->where('status', 'menunggu_verifikasi')->count(), 'revision' => $all->where('status', 'perlu_revisi')->count(), 'today' => $all->filter(fn ($a) => $a->isScheduledOn(today()))->count(), 'surveyors' => $r->user()->can('surveys.view-all') ? User::role('surveyor')->count() : 1];
        $programs = $all->groupBy(fn ($a) => $a->student->program->name);
        $surveyors = $all->groupBy('user_id');
        $cities = $all->groupBy(fn ($a) => $a->student->address?->city ?? 'Belum diisi');
        $recent = (clone $q)->latest('updated_at')->limit(6)->get();
        $imports = $r->user()->can('imports.manage') ? ImportBatch::where('status', 'preview')->get() : collect();

        return view('dashboard', compact('kpis', 'programs', 'surveyors', 'cities', 'recent', 'imports'));
    }

    public function tasks(Request $r): View
    {
        $assignments = AssignmentQuery::filtered($r->user(), $r->all())->latest()->paginate(15)->withQueryString();

        return view('tasks', compact('assignments') + ['programs' => StudyProgram::all(), 'periods' => SurveyPeriod::all(), 'users' => User::role('surveyor')->get(), 'cities' => StudentAddress::distinct()->pluck('city')]);
    }

    public function map(Request $r): View
    {
        $data = AssignmentQuery::filtered($r->user(), $r->all())->get()->map(fn ($a) => ['name' => $a->student->name, 'program' => $a->student->program->name, 'status' => $a->status, 'surveyor' => $a->user->name, 'address' => $a->student->address?->address, 'city' => $a->student->address?->city, 'registered' => $a->student->address?->only(['latitude', 'longitude']), 'actual' => $a->survey?->location?->only(['latitude', 'longitude']), 'started_at' => $a->survey?->started_at?->toIso8601String(), 'url' => $a->survey ? '/surveys/'.$a->survey->uuid : '/tasks']);

        return view('map', compact('data'));
    }
}
