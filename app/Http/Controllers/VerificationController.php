<?php

namespace App\Http\Controllers;

use App\Enums\SurveyMethod;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VerificationController extends Controller
{
    /** @var array<string, string> */
    public const TABS = ['menunggu_verifikasi' => 'Menunggu verifikasi', 'perlu_revisi' => 'Perlu revisi', 'disetujui' => 'Disetujui', 'tidak_direkomendasikan' => 'Tidak direkomendasikan'];

    /**
     * Antrean verifikasi lintas metode survey; yang menunggu diurutkan dari kiriman paling lama.
     */
    public function index(Request $r): View
    {
        $filters = $r->validate(['status' => ['nullable', Rule::in(array_keys(self::TABS))], 'q' => 'nullable|string|max:100', 'method' => ['nullable', Rule::enum(SurveyMethod::class)], 'user_id' => 'nullable|integer']);
        $status = $filters['status'] ?? 'menunggu_verifikasi';
        $surveys = Survey::with(['student.program', 'user', 'latestVerification.user'])
            ->where('status', $status)
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereHas('student', fn ($s) => $s->where(fn ($s) => $s->where('name', 'like', "%$v%")->orWhere('nim', 'like', "%$v%"))))
            ->when($filters['method'] ?? null, fn ($q, $v) => $q->where('method', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($status === 'menunggu_verifikasi', fn ($q) => $q->orderBy('submitted_at'), fn ($q) => $q->latest('updated_at'))
            ->paginate(25)->withQueryString();

        return view('verifications', [
            'surveys' => $surveys,
            'status' => $status,
            'filters' => $filters,
            'tabs' => self::TABS,
            'counts' => Survey::whereIn('status', array_keys(self::TABS))->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'surveyors' => User::role('surveyor')->orderBy('name')->get(),
        ]);
    }
}
