<?php

namespace Tests\Feature;

use App\Enums\SurveyMethod;
use App\Models\ImportBatch;
use App\Models\OnsiteInterview;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\SurveyAssignment;
use App\Models\SurveyCriterion;
use App\Models\SurveyPeriod;
use App\Models\User;
use App\Services\OnsiteInterviewService;
use App\Services\SurveyService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnsiteInterviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $first;

    private User $second;

    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-25 09:00:00', 'Asia/Makassar'));
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->admin = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->first = $this->surveyor('Petugas Pertama');
        $this->second = $this->surveyor('Petugas Kedua');
        $this->periodId = SurveyPeriod::firstOrFail()->id;
    }

    private function surveyor(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'active' => true, 'must_change_password' => false]);
        $user->assignRole('surveyor');

        return $user;
    }

    private function pooled(array $attributes = []): OnsiteInterview
    {
        return OnsiteInterview::factory()->create($attributes);
    }

    public function test_admin_imports_the_interview_list_from_csv_without_duplicates(): void
    {
        StudyProgram::firstOrCreate(['name' => 'Diploma Tiga Gizi']);
        $assigned = SurveyAssignment::factory()->create(['user_id' => $this->first->id]);
        $csv = ",,,,\nNO,NAMA,NIM,PRODI,\n1,Mahasiswa Onsite,711341126042,DIPLOMA TIGA GIZI,\n2,{$assigned->student->name},{$assigned->student->nim},Diploma Tiga Farmasi,\"Ibu meninggal, bekerja paruh waktu\"\n3,NIM Pendek,71153012300,Sarjana Terapan Kebidanan,\n,,,,\n";

        $this->actingAs($this->admin)->post('/imports', ['type' => 'onsite', 'file' => UploadedFile::fake()->createWithContent('wawancara.csv', $csv)])->assertRedirect();
        $batch = ImportBatch::latest('id')->firstOrFail();
        $this->assertCount(3, $batch->rows);
        $this->get('/imports/'.$batch->uuid)->assertOk()
            ->assertSee('Mahasiswa baru')
            ->assertSee('Ditugaskan survey lokasi ke Petugas Pertama · dialihkan saat diambil')
            ->assertSee('NIM 11 digit, periksa kembali')
            ->assertSee('Ibu meninggal, bekerja paruh waktu');

        $this->post('/imports/'.$batch->uuid.'/confirm', ['confirm' => 1, 'period_id' => $this->periodId])
            ->assertRedirect('/imports')->assertSessionHas('status', '3 mahasiswa ditambahkan ke daftar wawancara onsite.');

        $this->assertSame(3, OnsiteInterview::count());
        $this->assertSame('Ibu meninggal, bekerja paruh waktu', OnsiteInterview::where('student_id', $assigned->student_id)->value('notes'));
        $this->assertSame(1, StudyProgram::where('name', 'Diploma Tiga Gizi')->count());
        $this->assertNull(Student::where('nim', '711341126042')->firstOrFail()->address);
        $this->assertSame(['added' => 0, 'skipped' => 3], app(OnsiteInterviewService::class)->import($batch->rows, $this->periodId));
    }

    public function test_only_one_officer_can_claim_a_student(): void
    {
        $interview = $this->pooled();

        $this->actingAs($this->first)->post(route('onsite.claim', $interview))->assertRedirect()->assertSessionHas('status');
        $this->actingAs($this->second)->from(route('tasks.onsite'))->post(route('onsite.claim', $interview))
            ->assertRedirect(route('tasks.onsite'))->assertSessionHasErrors(['onsite' => 'Mahasiswa ini sudah diambil petugas lain.']);

        $assignment = $interview->fresh()->assignment;
        $this->assertSame($this->first->id, $assignment->user_id);
        $this->assertSame(SurveyMethod::Onsite, $assignment->method);
        $this->assertSame(1, SurveyAssignment::where('student_id', $interview->student_id)->count());

        $this->actingAs($this->first)->get(route('tasks.onsite'))->assertOk()->assertSee('Mulai wawancara')->assertSee('Lepas');
        $this->actingAs($this->second)->get(route('tasks.onsite'))->assertOk()->assertSee('Petugas Pertama')->assertDontSee('Mulai wawancara');
    }

    public function test_claiming_moves_an_unstarted_assignment_and_releasing_restores_it(): void
    {
        $original = SurveyAssignment::factory()->create(['user_id' => $this->second->id, 'scheduled_at' => '2026-12-01', 'scheduled_until' => '2026-12-02', 'status' => 'dijadwalkan', 'assignment_reference' => 'ST 12/2026']);
        $interview = $this->pooled(['student_id' => $original->student_id]);

        $this->actingAs($this->first)->post(route('onsite.claim', $interview))->assertSessionHas('status');
        $original->refresh();
        $this->assertSame($this->first->id, $original->user_id);
        $this->assertSame(SurveyMethod::Onsite, $original->method);

        $this->actingAs($this->second)->post(route('onsite.release', $interview))->assertForbidden();
        $this->actingAs($this->first)->post(route('onsite.release', $interview))->assertSessionHas('status');
        $original->refresh();
        $this->assertSame([$this->second->id, SurveyMethod::Location, '2026-12-01', '2026-12-02', 'dijadwalkan', 'ST 12/2026'], [$original->user_id, $original->method, $original->scheduled_at->toDateString(), $original->scheduled_until->toDateString(), $original->status, $original->assignment_reference]);
        $this->assertNull($interview->fresh()->claimed_by);
    }

    public function test_started_interviews_cannot_be_released_and_surveyed_students_cannot_be_claimed(): void
    {
        $interview = $this->pooled();
        $this->actingAs($this->first)->post(route('onsite.claim', $interview));
        app(SurveyService::class)->start($interview->fresh()->assignment);
        $this->from(route('tasks.onsite'))->post(route('onsite.release', $interview))->assertSessionHasErrors(['onsite' => 'Wawancara sudah dimulai sehingga mahasiswa tidak dapat dilepas.']);

        $surveyed = SurveyAssignment::factory()->create(['user_id' => $this->second->id]);
        $this->actingAs($this->second);
        app(SurveyService::class)->start($surveyed);
        $taken = $this->pooled(['student_id' => $surveyed->student_id]);
        $this->actingAs($this->first)->from(route('tasks.onsite'))->post(route('onsite.claim', $taken))->assertSessionHasErrors(['onsite' => 'Mahasiswa ini sudah disurvey melalui survey lokasi.']);
        $this->assertSame($this->second->id, $surveyed->fresh()->user_id);
    }

    public function test_onsite_survey_needs_an_interview_photo_but_no_gps_address_or_parent_signature(): void
    {
        SurveyCriterion::query()->update(['evidence_mode' => 'required']);
        app(OnsiteInterviewService::class)->import([['name' => 'Tanpa Alamat', 'nim' => '711341126010', 'program' => 'Diploma Tiga Gizi', 'notes' => null]], $this->periodId);
        $interview = OnsiteInterview::firstOrFail();
        $this->actingAs($this->first)->post(route('onsite.claim', $interview));
        $survey = app(SurveyService::class)->start($interview->fresh()->assignment);

        $missing = app(SurveyService::class)->missing($survey->fresh());
        $this->assertContains('Unggah minimal 1 foto wawancara onsite.', $missing);
        foreach (['Data mahasiswa dan alamat wajib lengkap.', 'GPS belum diambil.', 'Foto bersama wajib diunggah.', 'Foto kriteria 1 wajib diisi.', 'Tanda tangan parent belum sesuai isi terakhir.'] as $notRequired) {
            $this->assertNotContains($notRequired, $missing);
        }
        $this->assertSame(['surveyor'], app(SurveyService::class)->signatureRoles($survey));
        $this->get('/surveys/'.$survey->uuid)->assertOk()->assertSee('Foto wawancara onsite')->assertDontSee('Lokasi kunjungan aktual');

        $this->postJson('/surveys/'.$survey->uuid.'/photos', ['revision' => $survey->revision, 'kind' => 'onsite', 'photo' => UploadedFile::fake()->image('wawancara.jpg', 800, 600)])->assertOk();
        $this->assertNotContains('Unggah minimal 1 foto wawancara onsite.', app(SurveyService::class)->missing($survey->fresh()));

        $location = SurveyAssignment::factory()->create(['user_id' => $this->first->id]);
        $locationSurvey = app(SurveyService::class)->start($location);
        $this->postJson('/surveys/'.$locationSurvey->uuid.'/photos', ['revision' => $locationSurvey->revision, 'kind' => 'onsite', 'photo' => UploadedFile::fake()->image('salah.jpg')])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind' => 'Foto wawancara onsite hanya untuk wawancara onsite.']);
    }

    public function test_list_focuses_on_unfinished_interviews(): void
    {
        $available = $this->pooled();
        $mine = $this->pooled();
        $done = $this->pooled();
        $this->actingAs($this->first)->post(route('onsite.claim', $mine));
        $this->actingAs($this->second)->post(route('onsite.claim', $done));
        $doneSurvey = app(SurveyService::class)->start($done->fresh()->assignment);
        $doneSurvey->update(['submitted_at' => now(), 'locked_at' => now(), 'status' => 'menunggu_verifikasi']);

        $this->actingAs($this->first)->get(route('tasks.onsite'))->assertOk()
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['belum_selesai' => 2, 'tersedia' => 1, 'saya' => 1, 'selesai' => 1, 'semua' => 3])
            ->assertViewHas('interviews', fn ($page) => $page->pluck('id')->all() === [$mine->id, $available->id]);
        $this->get(route('tasks.onsite', ['status' => 'selesai']))->assertSee($done->student->name)->assertDontSee($available->student->name);
        $this->get(route('tasks.onsite', ['status' => 'tidak-ada']))->assertSessionHasErrors('status');
    }
}
