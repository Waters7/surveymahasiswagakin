<?php

namespace App\Services;

use App\Enums\SurveyFailureReason;
use App\Enums\SurveyMethod;
use App\Enums\SurveyStatus;
use App\Events\SurveySubmitted;
use App\Models\Survey;
use App\Models\SurveyAssignment;
use App\Models\SurveyFailure;
use App\Models\SurveyStatusHistory;
use App\Models\SystemSetting;
use App\Models\TravelVisitLog;
use App\Notifications\SurveyNotice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
            $s = Survey::create(['survey_assignment_id' => $a->id, 'student_id' => $a->student_id, 'user_id' => $a->user_id, 'survey_form_version_id' => $form->id, 'travel_link_id' => $a->travel_link_id, 'criteria_snapshot' => $form->criteria->map->only(['id', 'position', 'label', 'evidence_mode', 'weight'])->all(), 'rules_snapshot' => $a->period->rule?->only(['minimum_criteria', 'minimum_weight', 'pkh_recommended', 'auto_suggest']) ?? [], 'settings_snapshot' => array_diff_key(SystemSetting::allValues(), [DeliverySchedule::KEY => true]), 'student_snapshot' => $a->student->load('program', 'parents', 'address')->toArray(), 'started_at' => now(), 'status' => 'draft', 'method' => $a->method]);
            foreach ($s->criteria_snapshot as $c) {
                $s->answers()->create(['criterion_id' => $c['id']]);
            }
            $a->update(['status' => 'sedang_disurvey']);
            TravelVisitLog::create(['survey_id' => $s->id, 'event' => 'survey_started', 'occurred_at' => now()]);
            Audit::record('survey.started', $s);

            return $s->fresh();
        });
    }

    /**
     * Mencatat kunjungan yang tidak dapat dilaksanakan beserta bukti gambarnya.
     * Penugasan tetap dapat dicoba lagi lewat Mulai survey.
     *
     * @param  list<UploadedFile>  $evidence
     */
    public function fail(SurveyAssignment $assignment, SurveyFailureReason $reason, ?string $notes, array $evidence = []): SurveyFailure
    {
        abort_unless(auth()->user()->can('surveys.work') && $assignment->user_id === auth()->id(), 403);
        $stored = [];
        try {
            foreach ($evidence as $file) {
                $bytes = $this->failureEvidenceImage($file, $assignment->student->name);
                $path = 'failures/'.$assignment->uuid.'/'.Str::uuid().'.jpg';
                Storage::disk('local')->put($path, $bytes);
                $stored[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255)];
            }

            return DB::transaction(function () use ($assignment, $reason, $notes, $stored) {
                $a = SurveyAssignment::lockForUpdate()->findOrFail($assignment->id);
                abort_if($a->survey?->submitted_at, 422, 'Survey sudah dikirim sehingga tidak dapat dicatat gagal.');
                $failure = $a->failures()->create(['user_id' => $a->user_id, 'reason' => $reason, 'notes' => filled($notes) ? trim($notes) : null, 'evidence' => $stored, 'occurred_at' => now()]);
                $a->update(['status' => SurveyStatus::Failed->value]);
                if ($a->travel) {
                    app(TravelReportBuilder::class)->invalidate($a->travel);
                }
                Audit::record('survey.failed', $a, [], ['reason' => $reason->value, 'evidence_count' => count($stored)]);

                return $failure;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete(array_column($stored, 'path'));
            throw $e;
        }
    }

    /**
     * Gambar bukti diperkecil dan disimpan ulang sebagai JPEG agar metadata asli (termasuk GPS) terbuang.
     */
    private function failureEvidenceImage(UploadedFile $file, string $studentName): string
    {
        $im = imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $im) {
            throw ValidationException::withMessages(['evidence' => 'Bukti gambar tidak dapat dibaca.']);
        }
        if (max(imagesx($im), imagesy($im)) > 1920) {
            $scaled = imagescale($im, (int) (imagesx($im) * 1920 / max(imagesx($im), imagesy($im))));
            imagedestroy($im);
            $im = $scaled;
        }
        imagefilledrectangle($im, 0, imagesy($im) - 46, imagesx($im), imagesy($im), imagecolorallocate($im, 10, 45, 39));
        $white = imagecolorallocate($im, 255, 255, 255);
        imagestring($im, 3, 12, imagesy($im) - 38, 'SURVEY GAKIN | Bukti gagal survey | '.$studentName, $white);
        imagestring($im, 3, 12, imagesy($im) - 20, now()->format('d-m-Y H:i').' WITA | Poltekkes Kemenkes Manado', $white);
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
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
        return ['student' => $s->student_snapshot, 'criteria' => $s->criteria_snapshot, 'rules' => $s->rules_snapshot, 'settings' => $s->settings_snapshot, 'answers' => $s->answers()->orderBy('criterion_id')->get(['criterion_id', 'answer', 'detail', 'notes'])->toArray(), 'location' => $s->location?->only(['latitude', 'longitude', 'accuracy', 'captured_at']), 'photos' => $s->photos()->orderBy('id')->get(['uuid', 'criterion_id', 'kind', 'sha256'])->toArray(), 'pkh' => $s->pkh, 'residence' => $s->residence, 'recommendation' => $s->recommendation, 'conclusion' => $s->conclusion, 'group_photo_reason' => $s->group_photo_reason] + ($s->isVideoCall() ? ['method' => SurveyMethod::VideoCall->value] : []);
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
        $isVideoCall = $s->isVideoCall();
        foreach ($s->criteria_snapshot as $c) {
            if (! $s->answers->firstWhere('criterion_id', $c['id'])?->answer) {
                $errors[] = 'Kriteria '.$c['position'].' belum dijawab.';
            }
            if (! $isVideoCall && $c['evidence_mode'] === 'required' && ! $s->photos->contains('criterion_id', $c['id'])) {
                $errors[] = 'Foto kriteria '.$c['position'].' wajib diisi.';
            }
        }
        if ($isVideoCall && ! $s->photos->contains('kind', 'video_call')) {
            $errors[] = 'Unggah minimal 1 tangkapan layar video call.';
        }
        if (! $isVideoCall && ($settings['gps_required'] ?? true) && ! $s->location) {
            $errors[] = 'GPS belum diambil.';
        }
        if (! $isVideoCall && ($settings['group_photo_required'] ?? true) && ! $s->photos->contains('kind', 'group')) {
            $errors[] = 'Foto bersama wajib diunggah.';
        }
        if (! $isVideoCall && ! ($settings['group_photo_required'] ?? true) && ! $s->photos->contains('kind', 'group') && ! $s->group_photo_reason) {
            $errors[] = 'Isi alasan tidak ada foto bersama.';
        }
        if ($s->pkh === true && ! $s->photos->contains('kind', 'pkh')) {
            $errors[] = 'Bukti penerima PKH wajib diunggah berupa foto atau dokumen PDF.';
        }
        if ($s->pkh === null) {
            $errors[] = 'Status PKH belum diisi.';
        }
        if (! $s->recommendation || ! $s->conclusion) {
            $errors[] = 'Rekomendasi dan kesimpulan wajib diisi.';
        }
        if ($signatures && ($settings['signature_required'] ?? true)) {
            foreach ($this->signatureRoles($s) as $role) {
                if (! $s->signatures->contains(fn ($sig) => $sig->role === $role && hash_equals($sig->document_hash, $this->digest($s)))) {
                    $errors[] = 'Tanda tangan '.$role.' belum sesuai isi terakhir.';
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public function signatureRoles(Survey $s): array
    {
        return ($s->method ?? SurveyMethod::Location)->signatureRoles();
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
            Audit::record('survey.'.$decision, $s, ['status' => $from], ['status' => $next, 'reason' => $reason, 'self_verified' => $s->user_id === auth()->id()]);
            $s->user->notify(new SurveyNotice('Survey '.$s->student->name.': '.str_replace('_', ' ', $next), '/surveys/'.$s->uuid));
        });
    }
}
