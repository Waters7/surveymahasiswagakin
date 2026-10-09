<?php

namespace Database\Seeders;

use App\Models\StudyProgram;
use Illuminate\Database\Seeder;

class StudyProgramSeeder extends Seeder
{
    public function run(): void
    {
        // Ejaan mengikuti workbook sumber agar import tidak membentuk prodi ganda.
        foreach ([
            'Diploma Tiga Teknologi Laboratorium Medis', 'Diploma Tiga Keperawatan',
            'Diploma Tiga Farmasi', 'Sarjana Terapan Sanitasi Lingkungan',
            'Sarjana Terapan Gizi dan Dietetika', 'DIploma Tiga Kebidanan',
            'Sarjana Terapan Keperawatan', 'Diploma Tiga Gizi',
            'Sarjana Terapan Kebidanan', 'Diploma Tiga Kesehatan Gigi',
            'Sarter Promkes', 'Diploma Tiga Sanitasi',
        ] as $name) {
            StudyProgram::firstOrCreate(['name' => $name]);
        }
    }
}
