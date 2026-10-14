<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\SurveyPeriod;
use App\Models\User;
use App\Services\Audit;
use App\Services\ImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    public function index(): View
    {
        return view('imports', ['batches' => ImportBatch::latest()->paginate(20)]);
    }

    public function upload(Request $r, ImportService $service): RedirectResponse
    {
        $d = $r->validate(['file' => 'required|file|mimes:xlsx,xls|max:20480', 'type' => 'required|in:students,users', 'sheet' => 'nullable|string|max:100', 'mapping' => 'nullable|json', 'survey_method' => 'nullable|in:lokasi,video_call']);
        $map = empty($d['mapping']) ? null : json_decode($d['mapping'], true);
        if ($map) {
            $required = $d['type'] === 'users' ? ['name', 'nip', 'phone', 'email', 'position', 'unit'] : ['name', 'nim', 'semester', 'program', 'phone', 'father_job', 'mother_job', 'father_education', 'mother_education', 'father_phone', 'mother_phone', 'address', 'city', 'team', 'schedule'];
            abort_unless(is_array($map) && ! array_diff($required, array_keys($map)) && ! array_diff(array_keys($map), $required), 422, 'Mapping kolom tidak lengkap.');
            foreach ($map as $v) {
                abort_unless(is_int($v) && $v >= 0 && $v <= 200, 422);
            }
        }
        $b = $service->preview($r->file('file')->getRealPath(), $d['type'], $r->file('file')->getClientOriginalName(), $map, $d['sheet'] ?? '', $d['survey_method'] ?? null);

        return redirect('/imports/'.$b->uuid);
    }

    public function correct(Request $r, ImportBatch $batch, ImportService $service): RedirectResponse
    {
        $d = $r->validate(['row' => 'required|integer|min:0', 'name' => 'required|string|max:255', 'identifier' => 'required|string|max:32', 'address' => 'nullable|string|max:2000', 'program' => 'nullable|string|max:255']);
        DB::transaction(function () use ($d, $batch, $service) {
            $b = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($b->status === 'preview' && isset($b->rows[$d['row']]), 409);
            $rows = $b->rows;
            $before = $rows[$d['row']];
            $rows[$d['row']]['name'] = $d['name'];
            $rows[$d['row']][$b->type === 'users' ? 'nip' : 'nim'] = $d['identifier'];
            if ($b->type === 'students') {
                $rows[$d['row']]['address'] = $d['address'];
                $rows[$d['row']]['program'] = $d['program'];
            }
            $b->update(['rows' => $rows, 'errors' => $service->errors($rows, $b->type)]);
            Audit::record('import.row_corrected', $b, $before, $rows[$d['row']]);
        });

        return back()->with('status', 'Koreksi disimpan; validasi dihitung ulang.');
    }

    public function show(ImportBatch $batch): View
    {
        $users = User::role('surveyor')->where('active', true)->orderBy('name')->get();
        $teams = collect($batch->rows)->pluck('team')->filter()->unique()->values();

        return view('import-preview', ['batch' => $batch, 'users' => $users, 'periods' => SurveyPeriod::all(), 'suggestedSurveyors' => $teams->mapWithKeys(fn (string $team): array => [$team => app(ImportService::class)->suggestSurveyor($team, $users)?->id])->all()]);
    }

    public function confirm(Request $r, ImportBatch $batch, ImportService $service): RedirectResponse
    {
        $d = $r->validate(['confirm' => 'accepted', 'team_map' => 'nullable|array', 'team_map.*' => 'nullable|integer|exists:users,id', 'period_id' => 'nullable|integer|exists:survey_periods,id']);
        $count = $service->confirm($batch, $d['team_map'] ?? [], $d['period_id'] ?? null);

        return redirect('/imports')->with('status', "$count data berhasil diimpor.");
    }
}
