<?php

namespace App\Services;

use App\Enums\SurveyFailureReason;
use App\Models\SurveyFailure;
use App\Models\TravelLink;
use Illuminate\Support\Facades\DB;

class TravelReportBuilder
{
    public function build(TravelLink $t): array
    {
        $surveys = $t->surveys()->where('user_id', $t->user_id)->whereNotNull('locked_at')->whereNotNull('submitted_at')->orderBy('started_at')->get();
        $failures = SurveyFailure::with('assignment.student.address')
            ->whereHas('assignment', fn ($a) => $a->where('travel_link_id', $t->id)->where('user_id', $t->user_id))
            ->whereBetween('occurred_at', [$t->starts_at->copy()->startOfDay(), $t->ends_at->copy()->endOfDay()])
            ->orderBy('occurred_at')->get();
        $completedByDate = $surveys->groupBy(fn ($s) => $s->started_at->format('Y-m-d'));
        $failedByDate = $failures->groupBy(fn ($f) => $f->occurred_at->format('Y-m-d'));
        $activities = [];
        foreach ($completedByDate->keys()->merge($failedByDate->keys())->unique()->sort() as $date) {
            $completed = $completedByDate->get($date, collect());
            $failed = $failedByDate->get($date, collect())->reject(fn ($f) => $completed->contains('survey_assignment_id', $f->survey_assignment_id))->keyBy('survey_assignment_id');
            if ($completed->isEmpty() && $failed->isEmpty()) {
                continue;
            }
            $sentences = [];
            if ($completed->isNotEmpty()) {
                $sentences[] = 'Melaksanakan Survey GAKIN terhadap '.$completed->count().' mahasiswa: '.$completed->map(fn ($s) => $s->student_snapshot['name'])->implode(', ').'.';
            }
            if ($failed->isNotEmpty()) {
                $sentences[] = 'Survey belum berhasil dilaksanakan terhadap '.$failed->count().' mahasiswa: '.$failed->map(fn ($f) => $f->assignment->student->name.' ('.($f->reason === SurveyFailureReason::Other ? $f->reasonText() : lcfirst($f->reasonText())).')')->implode(', ').'.';
            }
            $locations = $completed->map(fn ($s) => $s->student_snapshot['address']['city'] ?? '')->merge($failed->map(fn ($f) => $f->assignment->student->address?->city ?? ''))->filter()->unique()->values()->all();
            $starts = $completed->pluck('started_at')->merge($failed->pluck('occurred_at'));
            $ends = $completed->pluck('submitted_at')->merge($failed->pluck('occurred_at'));
            $activities[] = ['date' => $date, 'start_time' => $starts->min()->format('H:i'), 'end_time' => $ends->max()->format('H:i'), 'description' => implode(' ', $sentences), 'locations' => $locations];
        }
        $total = $t->assignments()->where('user_id', $t->user_id)->count();
        $done = $surveys->count();
        $unreached = $failures->reject(fn ($f) => $surveys->contains('survey_assignment_id', $f->survey_assignment_id))->unique('survey_assignment_id')->count();
        $summary = "Survey GAKIN telah diselesaikan terhadap $done dari $total target penugasan.".($unreached ? " $unreached target belum berhasil disurvey karena kendala kunjungan." : '');

        return ['external_source' => 'survey-gakin', 'external_reference' => 'SG-'.$t->uuid, 'travel_uuid' => $t->pangi_trip_id, 'daily_activities' => $activities, 'target_count' => $total, 'completed_count' => $done, 'result_summary' => $summary, 'conclusion' => $done === $total && $total > 0 ? 'Seluruh target survey telah selesai.' : ($total - $done).' target masih memerlukan tindak lanjut.', 'follow_up' => 'Verifikasi dan rekapitulasi hasil Survey GAKIN.'];
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
