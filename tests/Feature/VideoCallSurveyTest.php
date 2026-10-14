<?php

namespace Tests\Feature;

use App\Enums\SurveyMethod;
use App\Models\ImportBatch;
use App\Models\Survey;
use App\Models\SurveyAssignment;
use App\Models\SurveyPeriod;
use App\Models\User;
use App\Services\ImportService;
use App\Services\SurveyService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class VideoCallSurveyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    private function surveyor(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'active' => true, 'must_change_password' => false]);
        $user->assignRole('surveyor');

        return $user;
    }

    private function videoCallSurvey(): Survey
    {
        $assignment = SurveyAssignment::factory()->create(['method' => 'video_call', 'scheduled_at' => null]);
        $this->actingAs($assignment->user);
        $survey = app(SurveyService::class)->start($assignment);

        return app(SurveyService::class)->save($survey, [
            'revision' => $survey->revision, 'pkh' => false, 'recommendation' => 'perlu_verifikasi', 'conclusion' => 'Video call dengan ibu mahasiswa.',
            'answers' => collect($survey->criteria_snapshot)->map(fn (array $c): array => ['criterion_id' => $c['id'], 'answer' => 'ya', 'detail' => 'Dikonfirmasi lewat video call', 'notes' => ''])->all(),
        ]);
    }

    private function signatureImage(): string
    {
        $im = imagecreatetruecolor(300, 100);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagesetthickness($im, 5);
        imageline($im, 10, 80, 290, 15, imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    public function test_video_call_tasks_are_listed_separately_with_family_phone_numbers(): void
    {
        $location = SurveyAssignment::factory()->create();
        $location->student->update(['name' => 'Mahasiswa Lokasi']);
        $videoCall = SurveyAssignment::factory()->create(['user_id' => $location->user_id, 'method' => 'video_call', 'scheduled_at' => null]);
        $videoCall->student->update(['name' => 'Mahasiswa Video Call', 'phone' => '6281234500011']);
        $videoCall->student->parents()->create(['relation' => 'ibu', 'name' => 'Ibu Uji', 'phone' => '081234500022']);

        $this->actingAs($location->user)->get(route('tasks.location'))->assertOk()->assertSee('Mahasiswa Lokasi')->assertDontSee('Mahasiswa Video Call');
        $this->get(route('tasks.video-call'))->assertOk()
            ->assertSee('Mahasiswa Video Call')->assertDontSee('Mahasiswa Lokasi')
            ->assertSee('href="tel:+6281234500022"', false)
            ->assertSee('href="https://wa.me/6281234500022"', false)
            ->assertSee('Ibu · Ibu Uji');
    }

    public function test_video_call_survey_needs_screenshot_and_only_the_surveyor_signature(): void
    {
        $survey = $this->videoCallSurvey();
        $missing = app(SurveyService::class)->missing($survey->fresh(), false);
        $this->assertContains('Unggah minimal 1 tangkapan layar video call.', $missing);
        $this->assertNotContains('GPS belum diambil.', $missing);
        $this->assertNotContains('Foto bersama wajib diunggah.', $missing);
        $this->assertSame(SurveyMethod::VideoCall, $survey->method);

        $this->post('/surveys/'.$survey->uuid.'/photos', ['revision' => $survey->revision, 'kind' => 'video_call', 'photo' => UploadedFile::fake()->image('vc.jpg', 640, 360)], ['Accept' => 'application/json'])->assertOk();
        $survey = $survey->fresh();
        $this->assertSame([], app(SurveyService::class)->missing($survey, false));

        $this->postJson('/surveys/'.$survey->uuid.'/signatures', ['revision' => $survey->revision, 'role' => 'parent', 'signed_by' => 'Ibu Uji', 'relation' => 'ibu', 'image' => $this->signatureImage(), 'consent' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/surveys/'.$survey->uuid.'/signatures', ['revision' => $survey->revision, 'role' => 'surveyor', 'signed_by' => 'surveyor', 'image' => $this->signatureImage(), 'consent' => true])->assertOk();
        $this->postJson('/surveys/'.$survey->uuid.'/submit', ['revision' => $survey->fresh()->revision])->assertOk();

        $this->assertSame('menunggu_verifikasi', $survey->fresh()->status);
        $this->get('/surveys/'.$survey->uuid)->assertOk();
    }

    public function test_location_survey_does_not_accept_video_call_screenshots(): void
    {
        $assignment = SurveyAssignment::factory()->create();
        $this->actingAs($assignment->user);
        $survey = app(SurveyService::class)->start($assignment);

        $this->post('/surveys/'.$survey->uuid.'/photos', ['revision' => $survey->revision, 'kind' => 'video_call', 'photo' => UploadedFile::fake()->image('vc.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_video_call_import_takes_only_vc_rows_and_suggests_surveyors(): void
    {
        $admin = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $admin->assignRole('super-admin');
        $octavianus = $this->surveyor('OCTAVIANUS ELRICTH WATERS MODAMI, S.Kom');
        $pantouw = $this->surveyor('LIDYA ANITA AFNI PANTOUW, SS, M.Hum');
        $this->surveyor('LIDYA ELISABET SYENNY RUMAJAR, AMd.Farm');
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['No', 'Nama', 'NIM', 'Semester', 'Prodi', 'NO HANDPHONE', 'PEKERJAAN ORANG TUA', '', 'PENDIDIKAN ORANG TUA', '', 'NO HANDPHONE', '', 'ALAMAT', 'KABUPATEN / KOTA', 'TIM SURVEI', 'TANGGAL PELAKSANAAN'],
            ['', '', '', '', '', '', 'AYAH', 'IBU', 'AYAH', 'IBU', 'AYAH', 'IBU'],
            [1, 'Mahasiswa Kunjungan', '711100000001', 'I', 'Diploma Tiga Farmasi', '81200000001', 'Petani', 'IRT', '', '', '', '', 'Desa Sonder', 'KABUPATEN MINAHASA', 'YOUDY PANGOW', '15-16 OKTOBER 2026'],
            [2, 'Mahasiswa Talaud', '711100000002', 'III', 'Diploma Tiga Gizi', '81200000002', 'Nelayan', 'IRT', '', '', '', '', 'Melonguane', 'KABUPATEN KEPULAUAN TALAUD', 'ELRITCH', 'HUBUNGI VC'],
            ['', 'Mahasiswa Tanpa Nomor Urut', '711100000003', 'V', 'Sarjana Terapan Kebidanan', '81200000003', 'Petani', 'IRT', '', '', '', '', 'Ondong', 'KABUPATEN SIAU TAGULANDANG BIARO', 'LIDYA PANTOUW', 'hubungi vc'],
            [3, 'Beasiswa Genap', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'gakin').'.xlsx';
        (new Xlsx($sheet))->save($path);

        $this->actingAs($admin)->post('/imports', ['type' => 'students', 'survey_method' => 'video_call', 'file' => new UploadedFile($path, 'survey gakin 2.xlsx', null, null, true)])->assertRedirect();
        $batch = ImportBatch::latest('id')->firstOrFail();
        $this->assertSame(['711100000002', '711100000003'], collect($batch->rows)->pluck('nim')->all());
        $this->assertSame([], $batch->errors);
        $this->get('/imports/'.$batch->uuid)->assertOk()->assertSee('Survey video call')
            ->assertSee('<option value="'.$octavianus->id.'" selected>', false)
            ->assertSee('<option value="'.$pantouw->id.'" selected>', false);
        $this->assertNull(app(ImportService::class)->suggestSurveyor('LIDYA', User::role('surveyor')->get()));

        $this->post('/imports/'.$batch->uuid.'/confirm', ['confirm' => 1, 'period_id' => SurveyPeriod::firstOrFail()->id, 'team_map' => ['ELRITCH' => $octavianus->id, 'LIDYA PANTOUW' => $pantouw->id]])->assertRedirect('/imports');
        $this->assertSame(2, SurveyAssignment::where('method', 'video_call')->count());
        $this->assertTrue(SurveyAssignment::whereHas('student', fn ($q) => $q->where('nim', '711100000002'))->where('user_id', $octavianus->id)->exists());
        @unlink($path);
    }
}
