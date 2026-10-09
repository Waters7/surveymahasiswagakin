<?php

namespace App\Services;

use App\Models\TravelLink;
use Illuminate\Support\Facades\DB;

class TravelReportBuilder
{
    public function build(TravelLink $t): array
    {
        $surveys = $t->surveys()->where('user_id', $t->user_id)->whereNotNull('locked_at')->whereNotNull('submitted_at')->orderBy('started_at')->get();
        $activities = [];
        foreach ($surveys->groupBy(fn ($s) => $s->started_at->format('Y-m-d')) as $date => $group) {
            $visits = $group->map(fn ($s) => ['student_name' => $s->student_snapshot['name'], 'study_program' => $s->student_snapshot['program']['name'], 'location' => $s->student_snapshot['address']['city'] ?? '', 'started_at' => $s->started_at->format('H:i'), 'completed_at' => $s->submitted_at->format('H:i')])->values()->all();
            $locations = array_values(array_unique(array_column($visits, 'location')));
            $activities[] = ['date' => $date, 'start_time' => $group->min('started_at')->format('H:i'), 'end_time' => $group->max('submitted_at')->format('H:i'), 'description' => 'Melaksanakan Survey GAKIN terhadap '.count($visits).' mahasiswa: '.implode(', ', array_column($visits, 'student_name')).'.', 'locations' => $locations, 'visits' => $visits];
        }
        $total = $t->assignments()->where('user_id', $t->user_id)->count();
        $done = $surveys->count();

        return ['external_source' => 'survey-gakin', 'external_reference' => 'SG-'.$t->uuid, 'travel_uuid' => $t->pangi_trip_id, 'daily_activities' => $activities, 'target_count' => $total, 'completed_count' => $done, 'result_summary' => "Survey GAKIN telah diselesaikan terhadap $done dari $total target penugasan.", 'conclusion' => $done === $total && $total > 0 ? 'Seluruh target survey telah selesai.' : ($total - $done).' target masih memerlukan tindak lanjut.', 'follow_up' => 'Verifikasi dan rekapitulasi hasil Survey GAKIN.'];
    }

    public function prepare(TravelLink $t): void
    {
        DB::transaction(function () use ($t) {
            $t = TravelLink::lockForUpdate()->findOrFail($t->id);
            abort_if(in_array($t->status, ['queued', 'locked']), 409, 'Draft sedang antre atau terkunci.');
            $draft = $this->build($t);
            $draft['follow_up'] = $t->draft['follow_up'] ?? $draft['follow_up'];
            $hash = hash('sha256', json_encode($draft));
            if ($t->draft_hash === $hash) {
                return;
            }
            $t->update(['draft' => $draft, 'draft_hash' => $hash, 'draft_version' => $t->draft_version + 1, 'idempotency_key' => 'GAKIN-'.$t->uuid.'-'.$hash, 'status' => $t->synced_at ? 'needs_resync' : 'ready']);
            Audit::record('pangi.prepared', $t, [], ['completed_count' => $draft['completed_count']]);
        });
    }

    public function isCurrent(TravelLink $travel): bool
    {
        if (! $travel->draft_hash || ! $travel->draft) {
            return false;
        }
        $current = $this->build($travel);
        $current['follow_up'] = $travel->draft['follow_up'] ?? $current['follow_up'];

        return hash_equals($travel->draft_hash, hash('sha256', json_encode($current)));
    }

    public function invalidate(TravelLink $travel): void
    {
        if (! $travel->draft_hash) {
            return;
        }
        TravelLink::whereKey($travel->id)->where('draft_hash', $travel->draft_hash)->update(['draft_hash' => null, 'status' => $travel->status === 'locked' ? 'locked' : 'needs_resync']);
    }
}
