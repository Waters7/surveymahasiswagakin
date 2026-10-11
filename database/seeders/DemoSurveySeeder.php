<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\Survey;
use App\Models\SurveyAssignment;
use App\Models\SurveyPeriod;
use App\Models\User;
use App\Services\SurveyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoSurveySeeder extends Seeder
{
    public function run(): void
    {
        $database = str_replace('\\', '/', (string) DB::connection()->getDatabaseName());
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'sqlite' || (! app()->environment('testing') && basename($database) !== 'gakin-demo.sqlite')) {
            throw new RuntimeException('Data contoh hanya boleh dibuat di SQLite gakin-demo.sqlite pada lingkungan local atau database testing.');
        }
        $previousUser = auth()->user();
        try {
            DB::transaction(function (): void {
                $this->call(DatabaseSeeder::class);
                $worker = User::firstOrCreate(['nip' => 'DEMO001'], ['name' => 'Surveyor CONTOH', 'email' => 'surveyor@example.invalid', 'password' => 'DEMO001', 'active' => true, 'must_change_password' => false]);
                $worker->assignRole('surveyor');
                $reviewer = User::firstOrCreate(['nip' => 'DEMOADMIN'], ['name' => 'Verifikator CONTOH', 'email' => 'admin@example.invalid', 'password' => 'DEMOADMIN', 'active' => true, 'must_change_password' => false]);
                $reviewer->assignRole('super-admin');
                $period = SurveyPeriod::where('name', 'Survey GAKIN 2026')->firstOrFail();
                foreach (['DEMO-GAKIN-001' => 'CONTOH — Draft latihan', 'DEMO-GAKIN-002' => 'CONTOH — Survey selesai'] as $nim => $name) {
                    $student = Student::firstOrCreate(['nim' => $nim], ['name' => $name, 'study_program_id' => StudyProgram::firstOrFail()->id, 'semester' => 'III', 'phone' => null]);
                    $student->address()->firstOrCreate(['student_id' => $student->id], ['address' => 'Alamat simulasi, Kecamatan Sario (bukan rumah mahasiswa)', 'city' => 'Manado', 'latitude' => 1.4634, 'longitude' => 124.8337]);
                    $student->parents()->firstOrCreate(['relation' => 'ayah'], ['name' => 'Ayah CONTOH', 'occupation' => 'Buruh harian', 'education' => 'SMA']);
                    $student->parents()->firstOrCreate(['relation' => 'ibu'], ['name' => 'Ibu CONTOH', 'occupation' => 'Mengurus rumah tangga', 'education' => 'SMP']);
                    $assignment = SurveyAssignment::firstOrCreate(['student_id' => $student->id, 'survey_period_id' => $period->id], ['user_id' => $worker->id, 'scheduled_at' => '2026-10-13', 'scheduled_until' => '2026-10-14', 'assignment_reference' => 'CONTOH LATIHAN — bukan surat tugas', 'status' => 'dijadwalkan']);
                    if ($assignment->survey) {
                        continue;
                    }
                    auth()->setUser($worker);
                    $service = app(SurveyService::class);
                    $survey = $service->start($assignment);
                    $survey->update(['settings_snapshot' => array_merge($survey->settings_snapshot, ['demo' => true])]);
                    $details = ['Ayah SMA; ibu SMP.', 'Rumah menumpang keluarga.', 'Lantai semen sederhana.', 'Dinding kayu sederhana.', 'Kamar mandi dipakai bersama.', 'Listrik 450 VA.', 'Memasak dengan tabung gas 3 kg.', 'Memiliki KIS; pengobatan melalui puskesmas.', 'Rp1.800.000 untuk 4 anggota: Rp450.000 per orang.', 'Memiliki satu sepeda motor lama.'];
                    $survey = $service->save($survey, ['revision' => $survey->revision, 'answers' => collect($survey->criteria_snapshot)->map(fn (array $criterion, int $index): array => ['criterion_id' => $criterion['id'], 'answer' => $index === 9 ? 'tidak' : 'ya', 'detail' => $details[$index] ?? 'Kondisi simulasi.', 'notes' => 'Data fiktif untuk latihan.'])->all(), 'residence' => ['ownership' => 'Menumpang keluarga lain', 'household_members' => 4, 'income' => 1800000], 'pkh' => true, 'recommendation' => 'direkomendasikan', 'conclusion' => 'CONTOH: keluarga berpenghasilan Rp450.000 per anggota per bulan dan memiliki PKH. Direkomendasikan dalam simulasi; bukan keputusan penerima bantuan.', 'location' => ['latitude' => 1.4635, 'longitude' => 124.8338, 'accuracy' => 12]]);
                    $this->addExamplePhoto($survey, $worker, 'group');
                    $this->addExamplePhoto($survey, $worker, 'pkh');
                    if ($nim === 'DEMO-GAKIN-001') {
                        continue;
                    }
                    foreach (['surveyor' => 'Surveyor CONTOH', 'parent' => 'Ayah CONTOH'] as $role => $signer) {
                        $path = 'demo/'.$survey->uuid.'/'.$role.'.png';
                        Storage::disk('local')->put($path, $this->exampleImage('CONTOH TANDA TANGAN', false));
                        $survey->signatures()->create(['role' => $role, 'signed_by' => $signer, 'relation' => $role === 'parent' ? 'ayah' : null, 'path' => $path, 'document_hash' => $service->digest($survey), 'signed_at' => now()]);
                    }
                    $survey = $service->submit($survey->fresh(), $survey->revision);
                    auth()->setUser($reviewer);
                    $service->verify($survey, 'approve', 'CONTOH: hasil simulasi disetujui untuk demonstrasi keluaran.');
                }
            });
        } finally {
            if ($previousUser) {
                auth()->setUser($previousUser);
            } else {
                auth()->forgetUser();
            }
        }
    }

    private function addExamplePhoto(Survey $survey, User $worker, string $kind): void
    {
        $bytes = $this->exampleImage($kind === 'pkh' ? 'CONTOH BUKTI PKH - BUKAN DOKUMEN ASLI' : 'CONTOH DOKUMENTASI - BUKAN FOTO KUNJUNGAN', true);
        $path = 'demo/'.$survey->uuid.'/'.$kind.'.jpg';
        Storage::disk('local')->put($path, $bytes);
        $survey->photos()->create(['user_id' => $worker->id, 'kind' => $kind, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'metadata' => ['demo' => true]]);
    }

    private function exampleImage(string $label, bool $photo): string
    {
        $image = imagecreatetruecolor(700, $photo ? 280 : 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 238, 246, 242));
        imagestring($image, 5, 25, 35, $label, imagecolorallocate($image, 20, 70, 55));
        ob_start();
        if ($photo) {
            imagejpeg($image, null, 90);
        } else {
            imagepng($image);
        }
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
