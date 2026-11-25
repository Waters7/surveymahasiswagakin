<?php

namespace App\Services;

use App\Enums\SurveyMethod;
use App\Models\OnsiteInterview;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\SurveyAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengambilan dan pelepasan mahasiswa wawancara onsite oleh petugas, serta impor daftarnya.
 */
class OnsiteInterviewService
{
    /**
     * Petugas mengambil mahasiswa: dibuatkan penugasan wawancara onsite, atau penugasan lain yang belum dimulai
     * dialihkan dengan data semula disimpan untuk dipulihkan.
     */
    public function claim(OnsiteInterview $interview, User $user): SurveyAssignment
    {
        abort_unless($user->active && $user->can('surveys.work'), 403);

        return DB::transaction(function () use ($interview, $user): SurveyAssignment {
            $i = OnsiteInterview::lockForUpdate()->findOrFail($interview->id);
            abort_if($i->claimed_by !== null, 409, 'Mahasiswa ini sudah diambil petugas lain.');
            $existing = SurveyAssignment::lockForUpdate()->where('student_id', $i->student_id)->where('survey_period_id', $i->survey_period_id)->first();
            abort_if($existing?->survey !== null, 422, 'Mahasiswa ini sudah disurvey melalui '.Str::lower(($existing?->method ?? SurveyMethod::Location)->label()).'.');
            $attributes = ['user_id' => $user->id, 'method' => SurveyMethod::Onsite, 'scheduled_at' => today(), 'scheduled_until' => null, 'status' => 'dijadwalkan', 'assignment_reference' => 'Wawancara onsite'];
            if ($existing) {
                $i->fill(['previous_user_id' => $existing->user_id, 'previous_method' => ($existing->method ?? SurveyMethod::Location)->value, 'previous_scheduled_at' => $existing->scheduled_at, 'previous_scheduled_until' => $existing->scheduled_until, 'previous_status' => $existing->status, 'previous_reference' => $existing->assignment_reference]);
                $existing->update($attributes);
                $assignment = $existing;
            } else {
                $assignment = SurveyAssignment::create(['student_id' => $i->student_id, 'survey_period_id' => $i->survey_period_id] + $attributes);
            }
            $i->fill(['claimed_by' => $user->id, 'claimed_at' => now(), 'survey_assignment_id' => $assignment->id])->save();
            Audit::record('onsite.claimed', $i, [], ['assignment_id' => $assignment->id, 'previous_user_id' => $i->previous_user_id]);

            return $assignment;
        });
    }

    /**
     * Mengembalikan mahasiswa ke daftar selama wawancara belum dimulai; penugasan semula dipulihkan.
     */
    public function release(OnsiteInterview $interview, User $user): void
    {
        DB::transaction(function () use ($interview, $user): void {
            $i = OnsiteInterview::lockForUpdate()->findOrFail($interview->id);
            abort_if($i->claimed_by === null, 409, 'Mahasiswa ini belum diambil.');
            abort_unless($i->claimed_by === $user->id || $user->can('assignments.manage'), 403);
            $assignment = $i->survey_assignment_id ? SurveyAssignment::lockForUpdate()->find($i->survey_assignment_id) : null;
            abort_if($assignment?->survey !== null, 422, 'Wawancara sudah dimulai sehingga mahasiswa tidak dapat dilepas.');
            abort_if($assignment && ! $i->previous_user_id && $assignment->failures()->exists(), 422, 'Sudah ada catatan gagal wawancara untuk mahasiswa ini; hubungi admin untuk memindahkannya.');
            $i->fill(['claimed_by' => null, 'claimed_at' => null, 'survey_assignment_id' => null])->save();
            if ($assignment && $i->previous_user_id) {
                $assignment->update(['user_id' => $i->previous_user_id, 'method' => $i->previous_method, 'scheduled_at' => $i->previous_scheduled_at, 'scheduled_until' => $i->previous_scheduled_until, 'status' => $i->previous_status ?? 'dijadwalkan', 'assignment_reference' => $i->previous_reference]);
            } elseif ($assignment) {
                $assignment->delete();
            }
            $i->fill(['previous_user_id' => null, 'previous_method' => null, 'previous_scheduled_at' => null, 'previous_scheduled_until' => null, 'previous_status' => null, 'previous_reference' => null])->save();
            Audit::record('onsite.released', $i, ['claimed_by' => $user->id]);
        });
    }

    /**
     * Memasukkan daftar wawancara; mahasiswa baru dibuat dari NIM, yang sudah ada di daftar dilewati.
     *
     * @param  list<array{name: string, nim: string, program: string, notes?: ?string}>  $rows
     * @return array{added: int, skipped: int}
     */
    public function import(array $rows, int $periodId): array
    {
        $added = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $student = Student::withTrashed()->where('nim', $row['nim'])->first();
            if ($student?->trashed()) {
                $student->restore();
            }
            $student ??= Student::create(['name' => $row['name'], 'nim' => $row['nim'], 'study_program_id' => $this->program($row['program'])->id]);
            $interview = OnsiteInterview::firstOrNew(['student_id' => $student->id, 'survey_period_id' => $periodId]);
            if ($interview->exists) {
                $skipped++;

                continue;
            }
            $interview->fill(['notes' => filled($row['notes'] ?? null) ? trim($row['notes']) : null])->save();
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Nama prodi dicocokkan tanpa membedakan huruf besar-kecil agar penulisan kapital tidak membuat prodi ganda.
     */
    private function program(string $name): StudyProgram
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return StudyProgram::all()->first(fn (StudyProgram $program): bool => Str::lower(trim($program->name)) === Str::lower($name))
            ?? StudyProgram::create(['name' => Str::title(Str::lower($name))]);
    }
}
