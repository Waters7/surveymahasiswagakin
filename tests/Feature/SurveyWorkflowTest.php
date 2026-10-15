<?php

namespace Tests\Feature;

use App\Enums\SurveyFailureReason;
use App\Jobs\SyncPangiDraft;
use App\Models\ImportBatch;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\Survey;
use App\Models\SurveyAssignment;
use App\Models\SurveyFailure;
use App\Models\SurveyPeriod;
use App\Models\TravelLink;
use App\Models\User;
use App\Services\ImportService;
use App\Services\PangiClient;
use App\Services\RecommendationService;
use App\Services\SurveyService;
use App\Services\TravelReportBuilder;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SurveyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected User $admin;

    protected SurveyAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-09 09:00:00', 'Asia/Makassar'));
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->worker = User::factory()->create(['name' => 'Surveyor Test', 'active' => true, 'must_change_password' => false]);
        $this->worker->assignRole('surveyor');
        $this->admin = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $p = StudyProgram::firstOrCreate(['name' => 'Diploma Tiga Farmasi']);
        $s = Student::create(['name' => 'Mahasiswa Test', 'nim' => '714840125001', 'study_program_id' => $p->id, 'semester' => 'III']);
        $s->address()->create(['address' => 'Alamat Test', 'city' => 'Minahasa']);
        $s->parents()->create(['relation' => 'ayah', 'name' => 'Ayah Test']);
        $this->assignment = SurveyAssignment::create(['student_id' => $s->id, 'user_id' => $this->worker->id, 'survey_period_id' => SurveyPeriod::first()->id, 'scheduled_at' => '2026-10-15']);
    }

    private function survey(): Survey
    {
        $this->actingAs($this->worker);

        return app(SurveyService::class)->start($this->assignment);
    }

    private function payload(Survey $s): array
    {
        return ['revision' => $s->revision, 'answers' => collect($s->criteria_snapshot)->map(fn ($c) => ['criterion_id' => $c['id'], 'answer' => 'ya', 'detail' => 'Kondisi diperiksa', 'notes' => ''])->all(), 'pkh' => true, 'recommendation' => 'direkomendasikan', 'conclusion' => 'Kunjungan telah dilakukan dan diverifikasi di lokasi.', 'location' => ['latitude' => 1.3, 'longitude' => 124.8, 'accuracy' => 9]];
    }

    private function signedSurvey(): Survey
    {
        $s = $this->survey();
        $s->settings_snapshot = array_merge($s->settings_snapshot, ['group_photo_required' => false]);
        $s->save();
        $data = $this->payload($s);
        $data['group_photo_reason'] = 'Tidak tersedia peserta lengkap saat kunjungan.';
        $s = app(SurveyService::class)->save($s, $data);
        $im = imagecreatetruecolor(300, 100);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imageline($im, 10, 70, 270, 20, imagecolorallocate($im, 0, 0, 0));
        imagesetthickness($im, 4);
        imageline($im, 30, 80, 270, 15, 0);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);
        Storage::disk('local')->put('pkh-test.png', $bytes);
        $s->photos()->create(['user_id' => $this->worker->id, 'kind' => 'pkh', 'path' => 'pkh-test.png', 'sha256' => hash('sha256', $bytes), 'metadata' => []]);
        foreach (['surveyor', 'parent'] as $role) {
            $path = 'signatures/'.$role.'.png';
            Storage::disk('local')->put($path, $bytes);
            $s->signatures()->create(['role' => $role, 'signed_by' => 'Penanda Test', 'path' => $path, 'document_hash' => app(SurveyService::class)->digest($s), 'signed_at' => now()]);
        }

        return $s->fresh();
    }

    public function test_guest_cannot_open_internal_pages(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_login_and_inactive_accounts(): void
    {
        $this->worker->update(['password' => 'ValidPassword123']);
        $this->post('/login', ['login' => $this->worker->email, 'password' => 'ValidPassword123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->worker);
        auth()->logout();
        $this->worker->update(['active' => false]);
        $this->post('/login', ['login' => $this->worker->email, 'password' => 'ValidPassword123'])->assertSessionHasErrors('login');
    }

    public function test_all_admin_pages_render(): void
    {
        $this->actingAs($this->admin);
        foreach (['/', '/tasks', '/students', '/users', '/roles', '/masters', '/imports', '/map', '/audit', '/pangi', '/profile', '/reports/visits', '/students/'.$this->assignment->student->uuid.'/edit'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_criterion_photo_upload_is_linked_to_its_answer(): void
    {
        $s = $this->survey();
        $criterion = collect($s->criteria_snapshot)->first(fn (array $c): bool => $c['evidence_mode'] !== 'disabled');

        $this->post('/surveys/'.$s->uuid.'/photos', ['revision' => $s->revision, 'kind' => 'evidence', 'criterion_id' => $criterion['id'], 'photo' => UploadedFile::fake()->image('kriteria.jpg', 800, 600)], ['Accept' => 'application/json'])->assertOk();

        $photo = $s->photos()->firstOrFail();
        $this->assertSame($criterion['id'], $photo->criterion_id);
        $this->assertTrue($s->answers()->where('criterion_id', $criterion['id'])->firstOrFail()->evidences()->where('survey_photo_id', $photo->id)->exists());
    }

    public function test_surveyor_cannot_see_or_edit_another_survey(): void
    {
        $s = $this->survey();
        $other = User::factory()->create();
        $other->assignRole('surveyor');
        $this->actingAs($other)->get('/surveys/'.$s->uuid)->assertForbidden();
        $this->postJson('/surveys/'.$s->uuid.'/draft', $this->payload($s))->assertForbidden();
        $this->post('/assignments/'.$this->assignment->uuid.'/start')->assertForbidden();
        $this->get('/tasks')->assertDontSee('Mahasiswa Test');
        $this->get('/users')->assertForbidden();
    }

    public function test_start_is_idempotent_and_snapshot_is_immutable(): void
    {
        $s = $this->survey();
        $again = app(SurveyService::class)->start($this->assignment);
        $this->assertEquals($s->id, $again->id);
        $c = $this->assignment->period->formVersion->criteria->first();
        $old = $s->criteria_snapshot[0]['label'];
        $c->update(['label' => 'Changed later']);
        $this->assertEquals($old, $s->fresh()->criteria_snapshot[0]['label']);
        $this->get('/surveys/'.$s->uuid)->assertOk();
    }

    public function test_draft_saves_gps_and_rejects_stale_revision(): void
    {
        $s = $this->survey();
        $this->postJson('/surveys/'.$s->uuid.'/draft', $this->payload($s))->assertOk()->assertJsonPath('revision', 1);
        $this->assertDatabaseHas('survey_locations', ['survey_id' => $s->id, 'latitude' => 1.3]);
        $this->postJson('/surveys/'.$s->uuid.'/draft', $this->payload($s))->assertStatus(409);
    }

    public function test_invalid_gps_and_unknown_criterion_are_rejected(): void
    {
        $s = $this->survey();
        $data = $this->payload($s);
        $data['location']['latitude'] = 999;
        $this->postJson('/surveys/'.$s->uuid.'/draft', $data)->assertUnprocessable();
        $data = $this->payload($s);
        $data['answers'][0]['criterion_id'] = 9999;
        $this->postJson('/surveys/'.$s->uuid.'/draft', $data)->assertUnprocessable();
        $this->assertEquals(0, $s->fresh()->revision);
    }

    public function test_required_fields_prevent_submission(): void
    {
        $s = $this->survey();
        $this->postJson('/surveys/'.$s->uuid.'/submit', ['revision' => 0])->assertUnprocessable();
        $this->assertNull($s->fresh()->locked_at);
    }

    public function test_submit_lock_revision_and_approval(): void
    {
        $s = $this->signedSurvey();
        $this->postJson('/surveys/'.$s->uuid.'/submit', ['revision' => $s->revision])->assertOk();
        $s->refresh();
        $this->assertNotNull($s->locked_at);
        $this->postJson('/surveys/'.$s->uuid.'/draft', $this->payload($s))->assertForbidden();
        $this->actingAs($this->admin)->post('/surveys/'.$s->uuid.'/verify', ['decision' => 'revision', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/surveys/'.$s->uuid.'/verify', ['decision' => 'revision', 'reason' => 'Mohon periksa bukti kembali.'])->assertRedirect();
        $this->assertNull($s->fresh()->verify_token);
        $this->assertTrue($s->fresh()->editable());
        $this->assertCount(0, $s->fresh()->signatures);
    }

    public function test_approval_preserves_locked_result_and_cannot_repeat(): void
    {
        $s = $this->signedSurvey();
        app(SurveyService::class)->submit($s, $s->revision);
        $this->actingAs($this->admin)->post('/surveys/'.$s->uuid.'/verify', ['decision' => 'approve', 'reason' => 'Bukti telah diperiksa lengkap.'])->assertRedirect();
        $this->assertEquals('disetujui', $s->fresh()->status);
        $this->assertNotNull($s->fresh()->locked_at);
        $this->post('/surveys/'.$s->uuid.'/verify', ['decision' => 'revision', 'reason' => 'Another change'])->assertForbidden();
    }

    public function test_editing_content_invalidates_both_signatures(): void
    {
        $s = $this->signedSurvey();
        $this->assertCount(2, $s->signatures);
        $this->postJson('/surveys/'.$s->uuid.'/draft', $this->payload($s))->assertOk();
        $this->assertCount(0, $s->fresh()->signatures);
    }

    public function test_uploads_are_private_and_locked_after_submit(): void
    {
        $s = $this->survey();
        $this->postJson('/surveys/'.$s->uuid.'/photos', ['revision' => 0, 'kind' => 'group', 'photo' => UploadedFile::fake()->image('group.jpg', 400, 300)])->assertOk();
        $p = $s->photos()->first();
        Storage::disk('local')->assertExists($p->path);
        $this->get('/media/'.$p->uuid)->assertOk();
        $this->actingAs(User::factory()->create())->get('/media/'.$p->uuid)->assertForbidden();
        $this->get('/storage/'.$p->path)->assertNotFound();
    }

    public function test_blank_signature_is_rejected(): void
    {
        $s = $this->signedSurvey();
        $im = imagecreatetruecolor(200, 100);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);
        $this->postJson('/surveys/'.$s->uuid.'/signatures', ['revision' => $s->revision, 'role' => 'parent', 'signed_by' => 'Parent', 'relation' => 'ayah', 'image' => 'data:image/png;base64,'.base64_encode($data), 'consent' => true])->assertUnprocessable();
    }

    public function test_qr_public_page_excludes_economic_data_and_detects_tamper(): void
    {
        $s = $this->signedSurvey();
        $s = app(SurveyService::class)->submit($s, $s->revision);
        auth()->logout();
        $this->get('/verify/'.$s->verify_token)->assertOk()->assertSee('Dokumen valid')->assertDontSee('Rp600.000')->assertDontSee('124.8')->assertDontSee('Alamat Test');
        $s->update(['conclusion' => 'Tampered']);
        $this->get('/verify/'.$s->verify_token)->assertSee('Dokumen tidak valid');
    }

    public function test_pdf_and_excel_are_real_documents(): void
    {
        $s = $this->signedSurvey();
        app(SurveyService::class)->submit($s, $s->revision);
        $r = $this->get('/surveys/'.$s->uuid.'/pdf')->assertOk();
        $this->assertStringContainsString('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $r = $this->get('/reports/excel')->assertOk();
        $this->assertStringContainsString('spreadsheet', $r->headers->get('Content-Type'));
    }

    public function test_recommendation_is_configurable_and_never_guesses_threshold(): void
    {
        $s = app(RecommendationService::class);
        $this->assertEquals('perlu_verifikasi', $s->suggest([['answer' => 'ya']], false, ['auto_suggest' => true, 'minimum_criteria' => null]));
        $this->assertEquals('direkomendasikan', $s->suggest([], true, ['auto_suggest' => true, 'pkh_recommended' => true]));
        $this->assertEquals('tidak_direkomendasikan', $s->suggest([['answer' => 'ya']], false, ['auto_suggest' => true, 'minimum_criteria' => 2]));
    }

    public function test_import_requires_confirmation_and_prevents_duplicate_batch(): void
    {
        $this->actingAs($this->admin);
        $rows = [['source_row' => 4, 'name' => 'Imported Student', 'nim' => '711111111111', 'program' => 'Farmasi', 'semester' => 'I', 'phone' => '6281111111', 'team' => 'Team A', 'schedule' => '15-16 OKTOBER 2026', 'address' => 'Alamat Test', 'city' => 'Minahasa']];
        $b = ImportBatch::create(['user_id' => $this->admin->id, 'type' => 'students', 'filename' => 'test.xlsx', 'rows' => $rows, 'mapping' => [], 'errors' => []]);
        $this->assertDatabaseMissing('students', ['nim' => '711111111111']);
        $this->get('/imports/'.$b->uuid)->assertOk();
        $this->post('/imports/'.$b->uuid.'/confirm', [])->assertSessionHasErrors('confirm');
        $this->post('/imports/'.$b->uuid.'/confirm', ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseHas('students', ['nim' => '711111111111']);
        $this->post('/imports/'.$b->uuid.'/confirm', ['confirm' => 1])->assertStatus(409);
    }

    public function test_bulk_assignment_is_validated(): void
    {
        $this->actingAs($this->admin)->post('/assignments', ['students' => [$this->assignment->student_id], 'user_id' => $this->worker->id, 'period_id' => $this->assignment->survey_period_id, 'scheduled_at' => '2027-10-01'])->assertStatus(422);
        $this->post('/assignments', ['students' => [$this->assignment->student_id], 'user_id' => $this->worker->id, 'period_id' => $this->assignment->survey_period_id, 'scheduled_at' => '2026-10-15'])->assertRedirect();
    }

    private function makeTravel(): TravelLink
    {
        $this->worker->update(['nip' => '199310182025061003']);

        return TravelLink::create(['user_id' => $this->worker->id, 'pangi_trip_id' => '22222222-2222-4222-8222-222222222222', 'spd_number' => 'SPD-TEST', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31', 'trip_data' => []]);
    }

    public function test_pangi_builder_contains_only_allowed_activity_facts(): void
    {
        $t = $this->makeTravel();
        $this->assignment->update(['travel_link_id' => $t->id]);
        $s = $this->signedSurvey();
        app(SurveyService::class)->submit($s, $s->revision);
        $b = app(TravelReportBuilder::class);
        $d = $b->build($t);
        $this->assertEquals(1, $d['completed_count']);
        $this->assertSame('Melaksanakan Survey GAKIN terhadap 1 mahasiswa: Mahasiswa Test.', $d['daily_activities'][0]['description']);
        $this->assertSame(['date', 'start_time', 'end_time', 'description', 'locations'], array_keys($d['daily_activities'][0]));
        $json = json_encode($d);
        foreach (['income', 'latitude', 'conclusion survey', 'answers', 'signature', 'Rp600', '124.8'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $json);
        }
        $b->prepare($t);
        $key = $t->fresh()->idempotency_key;
        $b->prepare($t->fresh());
        $this->assertEquals($key, $t->fresh()->idempotency_key);
    }

    public function test_pangi_prevents_other_user_trip_and_duplicate_dispatch(): void
    {
        Queue::fake();
        config(['pangi.enabled' => true]);
        $t = $this->makeTravel();
        app(TravelReportBuilder::class)->prepare($t);
        $t->refresh();
        $this->actingAs($this->admin)->get('/pangi/'.$t->uuid)->assertForbidden();
        $this->actingAs($this->worker);
        for ($i = 0; $i < 2; $i++) {
            $this->post('/pangi/'.$t->uuid.'/sync', ['confirm' => 1, 'draft_hash' => $t->draft_hash])->assertRedirect();
        }
        Queue::assertPushed(SyncPangiDraft::class, 1);
    }

    public function test_pangi_client_allows_plain_http_only_for_local_development(): void
    {
        $client = app(PangiClient::class);
        $this->assertTrue($client->allowedBaseUrl('https://pangi.poltekkes-manado.ac.id'));
        $this->assertFalse($client->allowedBaseUrl('http://127.0.0.1:8080'));

        $this->app['env'] = 'local';
        $this->assertTrue($client->allowedBaseUrl('http://127.0.0.1:8080'));
        $this->assertFalse($client->allowedBaseUrl('http://pangi.poltekkes-manado.ac.id'));
    }

    public function test_pangi_client_enforces_remote_owner_and_job_respects_lock(): void
    {
        $t = $this->makeTravel();
        $this->actingAs($this->worker);
        config(['pangi.enabled' => true, 'pangi.token' => 'test-token']);
        Http::fake(['*/trips/*' => Http::response(['data' => ['user_uuid' => 'different', 'status' => 'active']], 200)]);
        try {
            app(PangiClient::class)->trip($this->worker, $t->pangi_trip_id);
            $this->fail('Ownership was not checked');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
        Http::swap(new Factory);
        Http::fake(['*/report-status' => Http::response(['data' => ['locked' => true]]), '*/trips/*' => Http::response(['data' => ['user_uuid' => $this->worker->nip, 'status' => 'active']])]);
        app(TravelReportBuilder::class)->prepare($t);
        $t->refresh();
        (new SyncPangiDraft($t->id, $t->draft_hash))->handle(app(PangiClient::class));
        $this->assertEquals('locked', $t->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_phone_normalization_keeps_indonesian_prefix(): void
    {
        $this->assertEquals('6289510303773', ImportService::phone('62089510303773'));
        $this->assertEquals('6282194855954', ImportService::phone('82194855954'));
    }

    public function test_revision_invalidates_previously_prepared_travel_report(): void
    {
        Http::fake();
        $travel = $this->makeTravel();
        $this->assignment->update(['travel_link_id' => $travel->id]);
        $survey = $this->signedSurvey();
        app(SurveyService::class)->submit($survey, $survey->revision);
        app(TravelReportBuilder::class)->prepare($travel);
        $travel->refresh();
        $hash = $travel->draft_hash;
        $this->actingAs($this->admin)->post('/surveys/'.$survey->uuid.'/verify', ['decision' => 'revision', 'reason' => 'Periksa ulang bukti kunjungan.'])->assertRedirect();
        $this->assertNull($travel->fresh()->draft_hash);
        $this->assertSame('needs_resync', $travel->fresh()->status);
        (new SyncPangiDraft($travel->id, $hash))->handle(app(PangiClient::class));
        Http::assertNothingSent();
    }

    public function test_queued_report_does_not_send_when_underlying_assignments_change(): void
    {
        Http::fake();
        $travel = $this->makeTravel();
        app(TravelReportBuilder::class)->prepare($travel);
        $travel->refresh();
        $this->assignment->update(['travel_link_id' => $travel->id]);
        (new SyncPangiDraft($travel->id, $travel->draft_hash))->handle(app(PangiClient::class));
        Http::assertNothingSent();
        $this->assertNull($travel->fresh()->draft_hash);
        $this->assertSame('needs_resync', $travel->fresh()->status);
    }

    public function test_stale_report_cannot_be_queued_and_returns_409(): void
    {
        Queue::fake();
        config(['pangi.enabled' => true]);
        $travel = $this->makeTravel();
        app(TravelReportBuilder::class)->prepare($travel);
        $travel->refresh();
        $this->assignment->update(['travel_link_id' => $travel->id]);
        $this->actingAs($this->worker)->post('/pangi/'.$travel->uuid.'/sync', ['confirm' => 1, 'draft_hash' => $travel->draft_hash])->assertStatus(409);
        Queue::assertNothingPushed();
        $this->assertSame('ready', $travel->fresh()->status);
    }

    public function test_successful_pangi_sync_is_idempotent_and_keeps_edited_follow_up(): void
    {
        config(['pangi.enabled' => true, 'pangi.token' => 'test-token']);
        $travel = $this->makeTravel();
        $builder = app(TravelReportBuilder::class);
        $builder->prepare($travel);
        $travel->refresh();
        $this->actingAs($this->worker)->post('/pangi/'.$travel->uuid.'/draft', ['draft_hash' => $travel->draft_hash, 'follow_up' => 'Periksa hasil bersama koordinator.'])->assertRedirect();
        $builder->prepare($travel->fresh());
        $travel->refresh();
        $this->assertSame('Periksa hasil bersama koordinator.', $travel->draft['follow_up']);
        $base = rtrim(config('pangi.base_url'), '/').'/api/integrations/gakin/trips/'.$travel->pangi_trip_id;
        Http::fake([$base => Http::response(['data' => ['user_uuid' => $this->worker->nip, 'status' => 'active']]), $base.'/report-status' => Http::response(['data' => ['locked' => false]]), $base.'/report-draft' => Http::response(['data' => ['saved' => true]])]);
        $job = new SyncPangiDraft($travel->id, $travel->draft_hash);
        $job->handle(app(PangiClient::class));
        $job->handle(app(PangiClient::class));
        $this->assertSame('synced', $travel->fresh()->status);
        $this->assertNotNull($travel->fresh()->synced_at);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->hasHeader('Idempotency-Key', $travel->idempotency_key) && $request['follow_up'] === 'Periksa hasil bersama koordinator.');
    }

    public function test_surveyor_records_failed_visit_with_private_evidence_and_can_retry(): void
    {
        $url = '/assignments/'.$this->assignment->uuid.'/fail';
        $this->actingAs($this->worker)->post($url, ['reason' => 'tidak_di_rumah', 'notes' => 'Rumah terkunci', 'evidence' => [UploadedFile::fake()->image('panggilan.png', 2400, 1200)]])
            ->assertRedirect()->assertSessionHas('status', 'Survey Mahasiswa Test dicatat gagal: Tidak di rumah. Penugasan tetap dapat dicoba lagi.');

        $failure = $this->assignment->failures()->sole();
        $this->assertSame('gagal_survey', $this->assignment->fresh()->status);
        $this->assertSame('Rumah terkunci', $failure->notes);
        $this->assertCount(1, $failure->evidence);
        [$width] = getimagesizefromstring(Storage::disk('local')->get($failure->evidence[0]['path']));
        $this->assertSame(1920, $width);
        $this->get('/tasks')->assertOk()->assertSee('Gagal survey · 09 Oct 2026 09:00 WITA')->assertSee('Tidak di rumah — Rumah terkunci')->assertSee('/survey-failures/'.$failure->uuid.'/evidence/0');
        $this->get('/survey-failures/'.$failure->uuid.'/evidence/0')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/survey-failures/'.$failure->uuid.'/evidence/1')->assertNotFound();

        $other = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $other->assignRole('surveyor');
        $this->actingAs($other)->get('/survey-failures/'.$failure->uuid.'/evidence/0')->assertForbidden();
        $this->post($url, ['reason' => 'tidak_di_rumah'])->assertForbidden();

        $this->survey();
        $this->assertSame('sedang_disurvey', $this->assignment->fresh()->status);
    }

    public function test_failed_visit_requires_reason_note_for_other_and_image_evidence(): void
    {
        $url = '/assignments/'.$this->assignment->uuid.'/fail';
        $bag = 'failure-'.$this->assignment->uuid;
        $this->actingAs($this->worker);

        $this->post($url, [])->assertSessionHasErrors(['reason' => 'Pilih alasan survey gagal.'], null, $bag);
        $this->post($url, ['reason' => 'lainnya'])->assertSessionHasErrors(['notes' => 'Tuliskan alasan lainnya.'], null, $bag);
        $this->post($url, ['reason' => 'tidak_dapat_dihubungi', 'evidence' => [UploadedFile::fake()->create('catatan.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors(['evidence.0' => 'Bukti harus berupa gambar JPG, PNG, atau WEBP.'], null, $bag);
        $this->assertSame(0, $this->assignment->failures()->count());

        $s = $this->signedSurvey();
        app(SurveyService::class)->submit($s, $s->revision);
        $this->post($url, ['reason' => 'tidak_dapat_dihubungi'])->assertStatus(422);
    }

    public function test_pangi_report_lists_failed_visits_within_the_trip(): void
    {
        $t = $this->makeTravel();
        $this->assignment->update(['travel_link_id' => $t->id]);
        $unreachable = SurveyAssignment::factory()->create(['user_id' => $this->worker->id, 'travel_link_id' => $t->id]);
        $notHome = SurveyAssignment::factory()->create(['user_id' => $this->worker->id, 'travel_link_id' => $t->id]);
        SurveyFailure::factory()->create(['survey_assignment_id' => $this->assignment->id, 'occurred_at' => '2026-10-09 08:30']);
        SurveyFailure::factory()->create(['survey_assignment_id' => $unreachable->id, 'reason' => SurveyFailureReason::Other, 'notes' => 'HP tidak aktif sejak pagi', 'occurred_at' => '2026-10-09 08:00']);
        SurveyFailure::factory()->create(['survey_assignment_id' => $notHome->id, 'occurred_at' => '2026-10-10 14:15']);
        SurveyFailure::factory()->create(['survey_assignment_id' => $notHome->id, 'occurred_at' => '2027-01-02 10:00']);
        $s = $this->signedSurvey();
        app(SurveyService::class)->submit($s, $s->revision);

        $d = app(TravelReportBuilder::class)->build($t);

        $this->assertSame(['2026-10-09', '2026-10-10'], array_column($d['daily_activities'], 'date'));
        $this->assertSame('Melaksanakan Survey GAKIN terhadap 1 mahasiswa: Mahasiswa Test. Survey belum berhasil dilaksanakan terhadap 1 mahasiswa: '.$unreachable->student->name.' (HP tidak aktif sejak pagi).', $d['daily_activities'][0]['description']);
        $this->assertSame('08:00', $d['daily_activities'][0]['start_time']);
        $this->assertSame(['Minahasa', 'Manado'], $d['daily_activities'][0]['locations']);
        $this->assertSame('Survey belum berhasil dilaksanakan terhadap 1 mahasiswa: '.$notHome->student->name.' (tidak di rumah).', $d['daily_activities'][1]['description']);
        $this->assertSame('Survey GAKIN telah diselesaikan terhadap 1 dari 3 target penugasan. 2 target belum berhasil disurvey karena kendala kunjungan.', $d['result_summary']);
    }
}
