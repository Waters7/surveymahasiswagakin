<?php

namespace App\Http\Controllers;

use App\Jobs\SyncPangiDraft;
use App\Models\SurveyAssignment;
use App\Models\TravelLink;
use App\Services\Audit;
use App\Services\PangiClient;
use App\Services\TravelReportBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PangiController extends Controller
{
    private function owner(TravelLink $t): void
    {
        abort_unless($t->user_id === auth()->id() && auth()->user()->can('pangi.use'), 403);
    }

    public function index(): View
    {
        return view('pangi', ['travels' => TravelLink::with('user')->when(! auth()->user()->can('surveys.view-all'), fn ($q) => $q->where('user_id', auth()->id()))->latest()->get()]);
    }

    public function fetch(PangiClient $client): View
    {
        return view('pangi-trips', ['trips' => $client->trips(auth()->user())]);
    }

    public function connect(Request $r, PangiClient $client): RedirectResponse
    {
        $d = $r->validate(['trip_uuid' => 'required|uuid']);
        $remote = $client->trip($r->user(), $d['trip_uuid']);
        abort_unless(in_array($remote['status'] ?? '', ['active', 'approved']), 422, 'Perjalanan belum aktif.');
        $t = TravelLink::firstOrCreate(['user_id' => $r->user()->id, 'pangi_trip_id' => $d['trip_uuid']], ['spd_number' => $remote['spd_number'], 'starts_at' => $remote['starts_at'], 'ends_at' => $remote['ends_at'], 'trip_data' => collect($remote)->only(['uuid', 'user_uuid', 'spd_number', 'proposal_uuid', 'assignment_number', 'destination', 'starts_at', 'ends_at', 'status'])->all()]);
        Audit::record('pangi.connected', $t);

        return redirect('/pangi/'.$t->uuid);
    }

    public function show(TravelLink $travel): View
    {
        $this->owner($travel);

        return view('pangi-detail', ['travel' => $travel->load('logs', 'assignments.student'), 'assignments' => SurveyAssignment::with('student')->where('user_id', auth()->id())->whereNull('travel_link_id')->whereDoesntHave('survey')->get()]);
    }

    public function assign(Request $r, TravelLink $travel): RedirectResponse
    {
        $this->owner($travel);
        $d = $r->validate(['assignments' => 'required|array', 'assignments.*' => 'required|integer']);
        DB::transaction(function () use ($d, $travel) {
            $t = TravelLink::lockForUpdate()->findOrFail($travel->id);
            abort_if(in_array($t->status, ['queued', 'locked']), 409);
            foreach ($d['assignments'] as $id) {
                $a = SurveyAssignment::lockForUpdate()->findOrFail($id);
                abort_unless($a->user_id === auth()->id() && ! $a->survey && ! $a->travel_link_id && $a->scheduled_at && $a->scheduled_at->betweenIncluded($t->starts_at, $t->ends_at), 422, 'Penugasan harus milik Anda, belum dimulai, dan terjadwal dalam rentang perjalanan.');
                $a->update(['travel_link_id' => $t->id]);
            }app(TravelReportBuilder::class)->invalidate($t);
            Audit::record('pangi.assignments_linked', $t);
        });

        return back()->with('status', 'Penugasan terhubung.');
    }

    public function prepare(TravelLink $travel, TravelReportBuilder $b): RedirectResponse
    {
        $this->owner($travel);
        $b->prepare($travel);

        return back()->with('status', 'Draft dibuat dari kunjungan selesai. Periksa sebelum mengirim.');
    }

    public function edit(Request $r, TravelLink $travel): RedirectResponse
    {
        $this->owner($travel);
        $d = $r->validate(['follow_up' => 'required|string|max:3000', 'draft_hash' => 'required|string|size:64']);
        DB::transaction(function () use ($d, $travel) {
            $t = TravelLink::lockForUpdate()->findOrFail($travel->id);
            abort_if(in_array($t->status, ['queued', 'locked']) || $t->draft_hash !== $d['draft_hash'], 409);
            $draft = $t->draft;
            $draft['follow_up'] = $d['follow_up'];
            $hash = hash('sha256', json_encode($draft));
            $t->update(['draft' => $draft, 'draft_hash' => $hash, 'idempotency_key' => 'GAKIN-'.$t->uuid.'-'.$hash, 'draft_version' => $t->draft_version + 1, 'status' => 'ready']);
            Audit::record('pangi.draft_edited', $t);
        });

        return back();
    }

    public function sync(Request $r, TravelLink $travel): RedirectResponse
    {
        $this->owner($travel);
        $d = $r->validate(['confirm' => 'accepted', 'draft_hash' => 'required|string|size:64']);
        DB::transaction(function () use ($travel, $d) {
            $t = TravelLink::lockForUpdate()->findOrFail($travel->id);
            abort_unless(config('pangi.enabled'), 422);
            abort_if($t->draft_hash !== $d['draft_hash'], 409, 'Draft berubah; periksa lagi.');
            if (in_array($t->status, ['queued', 'synced'])) {
                return;
            }abort_unless(in_array($t->status, ['ready', 'needs_resync', 'failed']) && $t->draft, 422);
            abort_unless(app(TravelReportBuilder::class)->isCurrent($t), 409, 'Hasil survey berubah. Buat ulang dan periksa draft sebelum mengirim.');
            $t->update(['status' => 'queued']);
            SyncPangiDraft::dispatch($t->id, $t->draft_hash)->afterCommit();
            Audit::record('pangi.sync_requested', $t);
        });

        return back()->with('status', 'Draft masuk antrean sinkronisasi.');
    }
}
