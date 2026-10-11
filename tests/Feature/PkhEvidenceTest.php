<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\User;
use App\Services\SurveyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DemoSurveySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PkhEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function draft(): Survey
    {
        Storage::fake('local');
        $this->seed(DemoSurveySeeder::class);
        $survey = Survey::where('status', 'draft')->firstOrFail();
        $this->actingAs($survey->user);

        return $survey;
    }

    public function test_pkh_requires_proof_before_signing_or_submitting_but_non_pkh_does_not(): void
    {
        $survey = $this->draft();
        $survey->photos()->where('kind', 'pkh')->delete();
        $message = 'Bukti penerima PKH wajib diunggah berupa foto atau dokumen PDF.';

        $this->postJson('/surveys/'.$survey->uuid.'/signatures', ['revision' => $survey->revision, 'role' => 'surveyor', 'signed_by' => 'CONTOH', 'consent' => true, 'image' => 'data:image/png;base64,AA=='])->assertUnprocessable()->assertJsonValidationErrors('survey')->assertJsonFragment([$message]);
        $this->postJson('/surveys/'.$survey->uuid.'/submit', ['revision' => $survey->revision])->assertUnprocessable()->assertJsonFragment([$message]);
        $this->assertNull($survey->fresh()->locked_at);
        $survey = app(SurveyService::class)->save($survey, ['revision' => $survey->revision, 'pkh' => false]);
        $this->assertNotContains($message, app(SurveyService::class)->missing($survey, false));
    }

    public function test_pdf_proof_is_stored_privately_and_download_is_restricted_to_authorized_users(): void
    {
        $survey = $this->draft();
        $bytes = Pdf::loadHTML('<p>CONTOH BUKTI PKH</p>')->output();
        $file = UploadedFile::fake()->createWithContent('bukti-pkh.pdf', $bytes);

        $this->post('/surveys/'.$survey->uuid.'/photos', ['revision' => $survey->revision, 'kind' => 'pkh', 'photo' => $file], ['Accept' => 'application/json'])->assertOk();

        $proof = $survey->photos()->latest('id')->first();
        $this->assertSame('pkh', $proof->kind);
        $this->assertTrue($proof->isPdf());
        $this->assertSame($bytes, Storage::disk('local')->get($proof->path));
        $this->get('/media/'.$proof->uuid)->assertOk()->assertHeader('content-type', 'application/pdf');
        $other = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $other->assignRole('surveyor');
        $this->actingAs($other)->get('/media/'.$proof->uuid)->assertForbidden();
    }

    public function test_pkh_photo_is_accepted_and_removal_requires_new_proof(): void
    {
        $survey = $this->draft();
        $survey->photos()->where('kind', 'pkh')->delete();
        $this->post('/surveys/'.$survey->uuid.'/photos', ['revision' => $survey->revision, 'kind' => 'pkh', 'photo' => UploadedFile::fake()->image('pkh.jpg', 800, 600)], ['Accept' => 'application/json'])->assertOk();
        $survey->refresh();
        $this->assertSame([], app(SurveyService::class)->missing($survey, false));
        $proof = $survey->photos()->where('kind', 'pkh')->firstOrFail();

        $this->deleteJson('/media/'.$proof->uuid, ['revision' => $survey->revision])->assertOk()->assertJsonPath('pkh_evidence_count', 0);

        Storage::disk('local')->assertMissing($proof->path);
        $this->assertContains('Bukti penerima PKH wajib diunggah berupa foto atau dokumen PDF.', app(SurveyService::class)->missing($survey->fresh(), false));
    }

    public function test_other_documents_and_pdf_group_photos_are_rejected_without_changes(): void
    {
        $survey = $this->draft();
        $count = $survey->photos()->count();
        $this->post('/surveys/'.$survey->uuid.'/photos', ['revision' => $survey->revision, 'kind' => 'pkh', 'photo' => UploadedFile::fake()->createWithContent('fake.pdf', '<script>bad()</script>')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post('/surveys/'.$survey->uuid.'/photos', ['revision' => $survey->revision, 'kind' => 'group', 'photo' => UploadedFile::fake()->createWithContent('group.pdf', Pdf::loadHTML('Example')->output())], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertSame($count, $survey->photos()->count());
        $this->assertSame($survey->revision, $survey->fresh()->revision);
    }

    public function test_printed_report_supports_pdf_evidence_as_a_protected_attachment(): void
    {
        $this->draft();
        $survey = Survey::where('status', 'disetujui')->firstOrFail();
        $proof = $survey->photos()->where('kind', 'pkh')->firstOrFail();
        Storage::disk('local')->put('example-pkh.pdf', Pdf::loadHTML('CONTOH PKH')->output());
        $proof->update(['path' => 'example-pkh.pdf', 'metadata' => ['mime' => 'application/pdf', 'original_name' => 'contoh-pkh.pdf']]);

        $this->get('/surveys/'.$survey->uuid)->assertOk()->assertSee('Bukti penerima PKH (PDF)');
        $this->get('/surveys/'.$survey->uuid.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
