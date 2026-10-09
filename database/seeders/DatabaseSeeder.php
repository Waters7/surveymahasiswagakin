<?php

namespace Database\Seeders;

use App\Models\RecommendationRule;
use App\Models\SurveyFormVersion;
use App\Models\SurveyPeriod;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(StudyProgramSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['surveys.view-all', 'surveys.work', 'surveys.verify', 'students.manage', 'assignments.manage', 'imports.manage', 'users.manage', 'masters.manage', 'audit.view', 'reports.export', 'pangi.use'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $roles = ['super-admin' => $perms, 'koordinator' => ['surveys.view-all', 'surveys.verify', 'students.manage', 'assignments.manage', 'imports.manage', 'reports.export', 'pangi.use'], 'surveyor' => ['surveys.work', 'reports.export', 'pangi.use'], 'verifikator' => ['surveys.view-all', 'surveys.verify', 'reports.export'], 'pimpinan' => ['surveys.view-all', 'reports.export']];
        foreach ($roles as $name => $p) {
            Role::findOrCreate($name, 'web')->syncPermissions($p);
        }
        if (env('ADMIN_EMAIL') && env('ADMIN_PASSWORD')) {
            $u = User::firstOrCreate(['email' => env('ADMIN_EMAIL')], ['name' => 'Administrator GAKIN', 'password' => env('ADMIN_PASSWORD'), 'must_change_password' => true]);
            $u->assignRole('super-admin');
        }
        $f = SurveyFormVersion::firstOrCreate(['name' => 'Survey GAKIN 2026', 'version' => '1.0'], ['published' => true]);
        $labels = ['Pendidikan tertinggi orang tua maksimal SMA', 'Status kepemilikan rumah (milik sendiri, warisan, kos/kontrak, menumpang pada keluarga lain)', 'Jenis lantai tempat tinggal terbuat dari tanah/bambu/kayu murah/lantai semen', 'Jenis dinding tempat tinggal dari bambu/rumbia/kayu berkualitas rendah/tembok tanpa diplester', 'Tidak memiliki fasilitas buang air besar/bersama-sama dengan rumah tangga lain atau memiliki dengan fasilitas yang kurang', 'Sumber penerangan rumah tangga tidak menggunakan listrik atau daya listrik maksimal 900 kwh', 'Bahan bakar untuk memasak sehari-hari adalah kayu bakar/arang/minyak tanah atau tabung gas 3 kg', 'Tidak sanggup membayar biaya pengobatan di puskesmas/poliklinik atau memiliki kartu BPJS/KIS', 'Total sumber penghasilan keluarga di bawah Rp600.000 per bulan per anggota keluarga', 'Tidak memiliki kendaraan bermotor dan alat elektronik yang memadai seperti sepeda motor, televisi, kulkas, dan lain-lain'];
        foreach ($labels as $i => $label) {
            $f->criteria()->firstOrCreate(['position' => $i + 1], ['label' => $label, 'evidence_mode' => 'optional', 'weight' => 1]);
        }
        $p = SurveyPeriod::firstOrCreate(['name' => 'Survey GAKIN 2026'], ['academic_year' => '2026/2027', 'survey_form_version_id' => $f->id, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        RecommendationRule::firstOrCreate(['survey_period_id' => $p->id], ['minimum_criteria' => null, 'pkh_recommended' => true, 'auto_suggest' => true]);
        foreach (['app_name' => 'Sistem Informasi Survey GAKIN', 'document_number' => 'FM.III-11-01-rev.0', 'document_date' => '2024-02-02', 'document_revision' => '0', 'document_edition' => 'A', 'document_title' => 'FORMULIR SURVEY KRITERIA KELUARGA MISKIN (GAKIN) JALUR PMDP SIPENMARU', 'gps_required' => true, 'group_photo_required' => true, 'signature_required' => true, 'watermark_enabled' => true] as $k => $v) {
            SystemSetting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }
}
