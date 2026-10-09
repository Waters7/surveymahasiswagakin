<?php

namespace App\Services;

use App\Events\SurveySubmitted;
use App\Models\Survey;
use App\Models\SurveyAssignment;
use App\Models\SurveyStatusHistory;
use App\Models\SystemSetting;
use App\Models\TravelVisitLog;
use App\Notifications\SurveyNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SurveyService
{
    public function start(SurveyAssignment $assignment): Survey
    {
        abort_unless(auth()->user()->can('surveys.work') && $assignment->user_id === auth()->id(), 403);

        return DB::transaction(function () use ($assignment) {
            $a = SurveyAssignment::lockForUpdate()->findOrFail($assignment->id);
            if ($a->survey) {
                return $a->survey;
            }
            $form = $a->period->formVersion;
            if ($a->travel) {
                abort_unless(today()->betweenIncluded($a->travel->starts_at, $a->travel->ends_at), 422, 'Tanggal kunjungan di luar periode perjalanan.');
            } abort_unless($form->published, 422, 'Form belum dipublikasikan.');
            $s = Survey::create(['survey_assignment_id' => $a->id, 'student_id' => $a->student_id, 'user_id' => $a->user_id, 'survey_form_version_id' => $form->id, 'travel_link_id' => $a->travel_link_id, 'criteria_snapshot' => $form->criteria->map->only(['id', 'position', 'label', 'evidence_mode', 'weight'])->all(), 'rules_snapshot' => $a->period->rule?->only(['minimum_criteria', 'minimum_weight', 'pkh_recommended', 'auto_suggest']) ?? [], 'settings_snapshot' => array_diff_key(SystemSetting::allValues(), [DeliverySchedule::KEY => true]), 'student_snapshot' => $a->student->load('program', 'parents', 'address')->toArray(), 'started_at' => now(), 'status' => 'draft']);
            foreach ($s->criteria_snapshot as $c) {
                $s->answers()->create(['criterion_id' => $c['id']]);
            }
            $a->update(['status' => 'sedang_disurvey']);
            TravelVisitLog::create(['survey_id' => $s->id, 'event' => 'survey_started', 'occurred_at' => now()]);
            Audit::record('survey.started', $s);

            return $s->fresh();
        });
    }

    public function mutate(Survey $survey, int $revision, callable $operation): Survey
    {
        return DB::transaction(function () use ($survey, $revision, $operation) {
            $s = Survey::lockForUpdate()->findOrFail($survey->id);
            Gate::authorize('update', $s);
            abort_if($s->revision !== $revision, 409, 'Draft server berubah. Muat ulang dan cocokkan dengan salinan lokal.');
            $before = $this->content($s);
            $operation($s);
            $s->signatures()->delete();
            $s->revision++;
            $s->save();
            Audit::record('survey.updated', $s, $before, $this->content($s->fresh()));

            return $s->fresh();
        });
    }

    public function save(Survey $s, array $data): Survey
    {
        return $this->mutate($s, (int) $data['revision'], function ($s) use ($data) {
            $s->fill(collect($data)->only(['pkh', 'recommendation', 'conclusion', 'group_photo_reason', 'residence'])->all());
            foreach ($data['answers'] ?? [] as $a) {
                abort_unless(collect($s->criteria_snapshot)->contains('id', (int) $a['criterion_id']), 422);
                $s->answers()->where('criterion_id', $a['criterion_id'])->update(collect($a)->only(['answer', 'detail', 'notes'])->all());
            }
            if (isset($data['location'])) {
                $s->location()->updateOrCreate(['survey_id' => $s->id], $data['location'] + ['captured_at' => now()]);
                TravelVisitLog::create(['survey_id' => $s->id, 'event' => 'gps_captured', 'occurred_at' => now()]);
            }
            if (isset($data['student'])) {
                $snapshot = $s->student_snapshot;
                $snapshot['parents'] = $data['student']['parents'] ?? $snapshot['parents'];
                $s->student_snapshot = $snapshot;
            }
        });
    }

    public function content(Survey $s): array
    {
        return ['student' => $s->student_snapshot, 'criteria' => $s->criteria_snapshot, 'rules' => $s->rules_snapshot, 'settings' => $s->settings_snapshot, 'answers' => $s->answers()->orderBy('criterion_id')->get(['criterion_id', 'answer', 'detail', 'notes'])->toArray(), 'location' => $s->location?->only(['latitude', 'longitude', 'accuracy', 'captured_at']), 'photos' => $s->photos()->orderBy('id')->get(['uuid', 'criterion_id', 'kind', 'sha256'])->toArray(), 'pkh' => $s->pkh, 'residence' => $s->residence, 'recommendation' => $s->recommendation, 'conclusion' => $s->conclusion, 'group_photo_reason' => $s->group_photo_reason];
    }

    public function digest(Survey $s): string
    {
        return hash('sha256', json_encode($this->content($s->fresh()), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function missing(Survey $s, bool $signatures = true): array
    {
        $errors = [];
        $settings = $s->settings_snapshot;
        if (! $s->student_snapshot['name'] || ! ($s->student_snapshot['address']['address'] ?? null)) {
            $errors[] = 'Data mahasiswa dan alamat wajib lengkap.';
        }
        foreach ($s->criteria_snapshot as $c) {
            if (! $s->answers->firstWhere('criterion_id', $c['id'])?->answer) {
                $errors[] = 'Kriteria '.$c['position'].' belum dijawab.';
            }
            if ($c['evidence_mode'] === 'required' && ! $s->photos->contains('criterion_id', $c['id'])) {
                $errors[] = 'Foto kriteria '.$c['position'].' wajib diisi.';
            }
        }
        if (($settings['gps_required'] ?? true) && ! $s->location) {
            $errors[] = 'GPS belum diambil.';
        }
        if (($settings['group_photo_required'] ?? true) && ! $s->photos->contains('kind', 'group')) {
            $errors[] = 'Foto bersama wajib diunggah.';
        }
        if (! ($settings['group_photo_required'] ?? true) && ! $s->photos->contains('kind', 'group') && ! $s->group_photo_reason) {
            $errors[] = 'Isi alasan tidak ada foto bersama.';
        }
        if ($s->pkh === null) {
            $errors[] = 'Status PKH belum diisi.';
        }
        if (! $s->recommendation || ! $s->conclusion) {
            $errors[] = 'Rekomendasi dan kesimpulan wajib diisi.';
        }
        if ($signatures && ($settings['signature_required'] ?? true)) {
            foreach (['surveyor', 'parent'] as $role) {
                if (! $s->signatures->contains(fn ($sig) => $sig->role === $role && hash_equals($sig->document_hash, $this->digest($s)))) {
                    $errors[] = 'Tanda tangan '.$role.' belum sesuai isi terakhir.';
                }
            }
        }

        return $errors;
    }

    public function submit(Survey $s, int $revision): Survey
    {
        return DB::transaction(function () use ($s, $revision) {
            $s = Survey::lockForUpdate()->findOrFail($s->id);
            Gate::authorize('update', $s);
            abort_if($s->revision !== $revision, 409);
            if ($missing = $this->missing($s)) {
                throw ValidationException::withMessages(['survey' => $missing]);
            }
            $from = $s->status;
            $s->update(['status' => 'menunggu_verifikasi', 'submitted_at' => now(), 'locked_at' => now(), 'document_hash' => $this->digest($s), 'verify_token' => Str::random(64), 'revision' => $s->revision + 1]);
            $s->assignment->update(['status' => $s->status]);
            SurveyStatusHistory::create(['survey_id' => $s->id, 'user_id' => auth()->id(), 'from_status' => $from, 'to_status' => $s->status]);
            TravelVisitLog::create(['survey_id' => $s->id, 'event' => 'survey_completed', 'occurred_at' => now()]);
            if ($s->travel) {
                app(TravelReportBuilder::class)->invalidate($s->travel);
            }
            Audit::record('survey.submitted', $s);
            SurveySubmitted::dispatch($s);

            return $s;
        });
    }

    public function verify(Survey $s, string $decision, string $reason): void
    {
        DB::transaction(function () use ($s, $decision, $reason) {
            $s = Survey::lockForUpdate()->findOrFail($s->id);
            Gate::authorize('verify', $s);
            $next = match ($decision) {
                'approve' => 'disetujui','reject' => 'tidak_direkomendasikan','revision' => 'perlu_revisi'
            };
            $s->verifications()->create(['user_id' => auth()->id(), 'decision' => $decision, 'reason' => $reason]);
            $from = $s->status;
            $s->status = $next;
            if ($decision === 'revision') {
                $s->locked_at = null;
                $s->verify_token = null;
                $s->document_hash = null;
                $s->signatures()->delete();
                $s->revision++;
                if ($s->travel) {
                    app(TravelReportBuilder::class)->invalidate($s->travel);
                }
            }
            $s->save();
            $s->assignment->update(['status' => $next]);
            $s->histories()->create(['user_id' => auth()->id(), 'from_status' => $from, 'to_status' => $next, 'reason' => $reason]);
            Audit::record('survey.'.$decision, $s, ['status' => $from], ['status' => $next, 'reason' => $reason]);
            $s->user->notify(new SurveyNotice('Survey '.$s->student->name.': '.str_replace('_', ' ', $next), '/surveys/'.$s->uuid));
        });
    }
}
