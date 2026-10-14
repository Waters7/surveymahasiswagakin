<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSurveyRequest;
use App\Models\Survey;
use App\Models\SurveyAnswerEvidence;
use App\Models\SurveyAssignment;
use App\Models\SurveyPhoto;
use App\Services\Audit;
use App\Services\RecommendationService;
use App\Services\SurveyService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SurveyController extends Controller
{
    public function start(SurveyAssignment $assignment, SurveyService $service): RedirectResponse
    {
        $s = $service->start($assignment);

        return redirect('/surveys/'.$s->uuid);
    }

    public function show(Survey $survey, SurveyService $service): View
    {
        Gate::authorize('view', $survey);
        $survey->load('answers', 'photos', 'signatures', 'location', 'student.program', 'student.address', 'user', 'histories', 'verifications');
        $payload = ['uuid' => $survey->uuid, 'revision' => $survey->revision, 'answers' => $survey->answers->map->only(['criterion_id', 'answer', 'detail', 'notes'])->all(), 'pkh' => $survey->pkh, 'recommendation' => $survey->recommendation, 'conclusion' => $survey->conclusion, 'group_photo_reason' => $survey->group_photo_reason, 'residence' => $survey->residence ?? ['ownership' => '', 'household_members' => null, 'income' => null], 'location' => $survey->location?->only(['latitude', 'longitude', 'accuracy']), 'student' => ['parents' => $survey->student_snapshot['parents'] ?? []]];
        $suggestion = app(RecommendationService::class)->suggest($payload['answers'], (bool) $survey->pkh, $survey->rules_snapshot, $survey->criteria_snapshot);
        $missing = $service->missing($survey);

        return view('survey', compact('survey', 'payload', 'suggestion', 'missing'));
    }

    public function state(Survey $survey): JsonResponse
    {
        Gate::authorize('view', $survey);

        return response()->json(['uuid' => $survey->uuid, 'revision' => $survey->revision, 'answers' => $survey->answers->map->only(['criterion_id', 'answer', 'detail', 'notes'])->all(), 'pkh' => $survey->pkh, 'recommendation' => $survey->recommendation, 'conclusion' => $survey->conclusion, 'group_photo_reason' => $survey->group_photo_reason, 'residence' => $survey->residence ?? ['ownership' => '', 'household_members' => null, 'income' => null], 'location' => $survey->location?->only(['latitude', 'longitude', 'accuracy']), 'student' => ['parents' => $survey->student_snapshot['parents'] ?? []]]);
    }

    public function save(SaveSurveyRequest $r, Survey $survey, SurveyService $service): JsonResponse
    {
        $s = $service->save($survey, $r->validated());

        return response()->json(['revision' => $s->revision, 'saved_at' => now()->toIso8601String()]);
    }

    public function photo(Request $r, Survey $survey, SurveyService $service): JsonResponse
    {
        Gate::authorize('update', $survey);
        $upload = $r->file('photo');
        $photoRules = $r->input('kind') === 'pkh' && $upload instanceof UploadedFile && $upload->isValid() && $upload->getMimeType() === 'application/pdf'
            ? 'required|file|mimes:pdf|max:10240'
            : 'required|image|mimes:jpg,jpeg,png,webp|max:10240|dimensions:max_width=10000,max_height=10000';
        $d = $r->validate(['revision' => 'required|integer', 'photo' => $photoRules, 'kind' => 'required|in:evidence,group,pkh,video_call', 'criterion_id' => 'nullable|integer', 'captured_at' => 'nullable|date']);
        $path = null;
        try {
            $s = $service->mutate($survey, (int) $d['revision'], function ($s) use ($r, $d, &$path) {
                if ($d['kind'] === 'video_call' && ! $s->isVideoCall()) {
                    throw ValidationException::withMessages(['kind' => 'Tangkapan layar video call hanya untuk survey video call.']);
                }
                if ($d['kind'] === 'evidence') {
                    abort_unless(isset($d['criterion_id']), 422);
                    $criterion = collect($s->criteria_snapshot)->firstWhere('id', (int) $d['criterion_id']);
                    abort_unless($criterion && $criterion['evidence_mode'] !== 'disabled', 422);
                }
                $isPdf = $d['kind'] === 'pkh' && $r->file('photo')->getMimeType() === 'application/pdf';
                $bytes = file_get_contents($r->file('photo')->getRealPath());
                if ($isPdf && ! str_starts_with($bytes, '%PDF-')) {
                    throw ValidationException::withMessages(['photo' => 'Dokumen bukti PKH harus berupa PDF yang valid.']);
                }
                if (! $isPdf) {
                    $im = imagecreatefromstring($bytes);
                    abort_unless($im, 422);
                    $width = imagesx($im);
                    $height = imagesy($im);
                    if (max($width, $height) > 1920) {
                        $new = imagescale($im, (int) ($width * 1920 / max($width, $height)));
                        imagedestroy($im);
                        $im = $new;
                    }
                    if ($d['kind'] !== 'pkh' && ($s->settings_snapshot['watermark_enabled'] ?? true)) {
                        imagefilledrectangle($im, 0, imagesy($im) - 64, imagesx($im), imagesy($im), imagecolorallocate($im, 10, 45, 39));
                        $white = imagecolorallocate($im, 255, 255, 255);
                        imagestring($im, 3, 12, imagesy($im) - 54, 'SURVEY GAKIN | '.$s->student->name, $white);
                        imagestring($im, 3, 12, imagesy($im) - 36, now()->format('d-m-Y H:i').' WITA | '.($s->isVideoCall() ? 'Survey video call' : ($s->location?->latitude ?? 'GPS belum diambil').', '.($s->location?->longitude ?? '')), $white);
                        imagestring($im, 3, 12, imagesy($im) - 18, 'Poltekkes Kemenkes Manado', $white);
                    }ob_start();
                    imagejpeg($im, null, 85);
                    $bytes = ob_get_clean();
                    imagedestroy($im);
                }
                $path = 'surveys/'.$s->uuid.'/'.Str::uuid().($isPdf ? '.pdf' : '.jpg');
                Storage::disk('local')->put($path, $bytes);
                $p = $s->photos()->create(['user_id' => auth()->id(), 'criterion_id' => $d['kind'] === 'evidence' ? $d['criterion_id'] : null, 'kind' => $d['kind'], 'path' => $path, 'sha256' => hash('sha256', $bytes), 'metadata' => ['mime' => $isPdf ? 'application/pdf' : 'image/jpeg', 'original_name' => mb_substr($r->file('photo')->getClientOriginalName(), 0, 255), 'captured_at' => $d['captured_at'] ?? null, 'uploaded_at' => now()->toIso8601String(), 'location' => $s->location?->only(['latitude', 'longitude', 'accuracy']), 'device' => mb_substr($r->userAgent() ?? '', 0, 1000)]]);
                if ($p->criterion_id) {
                    SurveyAnswerEvidence::create(['survey_answer_id' => $s->answers()->where('criterion_id', $p->criterion_id)->firstOrFail()->id, 'survey_photo_id' => $p->id]);
                }
                Audit::record('photo.uploaded', $p);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }

        return response()->json(['revision' => $s->revision]);
    }

    public function removePhoto(Request $r, SurveyPhoto $photo, SurveyService $service): JsonResponse
    {
        $r->validate(['revision' => 'required|integer']);
        $s = $service->mutate($photo->survey, (int) $r->revision, function () use ($photo) {
            $photo->delete();
            Audit::record('photo.deleted', $photo);
        });
        Storage::disk('local')->delete($photo->path);

        return response()->json(['revision' => $s->revision, 'pkh_evidence_count' => $s->photos()->where('kind', 'pkh')->count()]);
    }

    public function media(SurveyPhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $photo->survey);

        return Storage::disk('local')->response($photo->path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], $photo->isPdf() ? 'attachment' : 'inline');
    }

    public function signature(Request $r, Survey $survey, SurveyService $service): JsonResponse
    {
        $d = $r->validate(['revision' => 'required|integer', 'role' => 'required|in:surveyor,parent', 'signed_by' => 'required|string|max:255', 'relation' => 'nullable|in:ayah,ibu,wali', 'image' => 'required|string|max:500000', 'consent' => 'accepted']);
        $path = null;
        try {
            return DB::transaction(function () use ($d, $r, $survey, $service, &$path) {
                $s = Survey::lockForUpdate()->findOrFail($survey->id);
                Gate::authorize('update', $s);
                abort_if($s->revision !== (int) $d['revision'], 409);
                if (! in_array($d['role'], $service->signatureRoles($s), true)) {
                    throw ValidationException::withMessages(['role' => 'Survey video call hanya ditandatangani oleh surveyor.']);
                }
                if ($errors = $service->missing($s, false)) {
                    throw ValidationException::withMessages(['survey' => $errors]);
                }abort_unless(preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $d['image'], $m), 422);
                $bytes = base64_decode($m[1], true);
                $info = @getimagesizefromstring($bytes);
                abort_unless($info && $info['mime'] === 'image/png' && $info[0] <= 2000 && $info[1] <= 1000, 422);
                $im = imagecreatefromstring($bytes);
                $ink = 0;
                for ($y = 0; $y < imagesy($im); $y += 2) {
                    for ($x = 0; $x < imagesx($im); $x += 2) {
                        $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                        if ($c['alpha'] < 100 && ($c['red'] + $c['green'] + $c['blue']) < 600) {
                            $ink++;
                        }
                    }
                }imagedestroy($im);
                abort_if($ink < 20, 422, 'Tanda tangan kosong.');
                if ($d['role'] === 'parent') {
                    abort_unless($d['relation'], 422);
                }
                $path = 'signatures/'.$s->uuid.'/'.Str::uuid().'.png';
                Storage::disk('local')->put($path, $bytes);
                $s->signatures()->updateOrCreate(['role' => $d['role']], ['signed_by' => $d['role'] === 'surveyor' ? $r->user()->name : $d['signed_by'], 'relation' => $d['relation'] ?? null, 'path' => $path, 'document_hash' => $service->digest($s), 'signed_at' => now(), 'ip' => $r->ip(), 'user_agent' => mb_substr($r->userAgent() ?? '', 0, 1000)]);
                Audit::record('signature.saved', $s, [], ['role' => $d['role']]);

                return response()->json(['revision' => $s->revision]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
    }

    public function submit(Request $r, Survey $survey, SurveyService $service): JsonResponse
    {
        $d = $r->validate(['revision' => 'required|integer']);
        $service->submit($survey, $d['revision']);

        return response()->json(['redirect' => '/surveys/'.$survey->uuid]);
    }

    public function verify(Request $r, Survey $survey, SurveyService $service): RedirectResponse
    {
        $d = $r->validate(['decision' => 'required|in:approve,reject,revision', 'reason' => 'required|string|min:5|max:4000']);
        $service->verify($survey, $d['decision'], $d['reason']);

        return back()->with('status', 'Keputusan verifikasi tersimpan.');
    }
}
