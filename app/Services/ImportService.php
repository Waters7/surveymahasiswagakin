<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\SurveyAssignment;
use App\Models\SurveyTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportService
{
    public static function phone($value): ?string
    {
        $s = preg_replace('/\D/', '', (string) $value);
        if (! $s) {
            return null;
        }
        if (str_starts_with($s, '620')) {
            $s = '62'.substr($s, 3);
        } elseif (str_starts_with($s, '0')) {
            $s = '62'.substr($s, 1);
        } elseif (str_starts_with($s, '8')) {
            $s = '62'.$s;
        }

        return $s;
    }

    public function preview(string $path, string $type, string $name, ?array $mapping = null, string $sheet = ''): ImportBatch
    {
        $book = IOFactory::load($path);
        $ws = $sheet ? $book->getSheetByName($sheet) : ($type === 'users' ? ($book->getSheetByName('LAPORAN DUK') ?? $book->getActiveSheet()) : $book->getActiveSheet());
        abort_unless($ws, 422, 'Sheet tidak ditemukan.');
        $raw = $ws->toArray(null, true, false, false);
        $map = $mapping ?? ($type === 'users' ? ['name' => 2, 'nip' => 6, 'phone' => 28, 'email' => 30, 'position' => 9, 'unit' => 34] : ['name' => 1, 'nim' => 2, 'semester' => 3, 'program' => 4, 'phone' => 5, 'father_job' => 6, 'mother_job' => 7, 'father_education' => 8, 'mother_education' => 9, 'father_phone' => 10, 'mother_phone' => 11, 'address' => 12, 'city' => 13, 'team' => 14, 'schedule' => 15]);
        $rows = [];
        foreach ($raw as $index => $row) {
            if (! is_numeric($row[0] ?? null) || ! trim((string) ($row[$map['name']] ?? ''))) {
                continue;
            }
            $r = ['source_row' => $index + 1];
            foreach ($map as $key => $col) {
                $r[$key] = trim(ltrim((string) ($row[(int) $col] ?? ''), "'"));
            }
            foreach (['phone', 'father_phone', 'mother_phone'] as $p) {
                if (isset($r[$p])) {
                    $r[$p] = self::phone($r[$p]);
                }
            }
            $rows[] = $r;
        }
        abort_unless(count($rows) > 0 && count($rows) <= 5000, 422, 'Tidak ada baris valid atau lebih dari 5.000 baris. Periksa sheet dan mapping.');

        return ImportBatch::create(['user_id' => auth()->id(), 'type' => $type, 'filename' => $name, 'rows' => $rows, 'mapping' => $map, 'errors' => $this->errors($rows, $type)]);
    }

    public function errors(array $rows, string $type): array
    {
        $errors = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $key = $type === 'users' ? 'nip' : 'nim';
            $id = $r[$key] ?? '';
            if (! $id || ! preg_match($type === 'users' ? '/^\d{18}$/' : '/^[A-Za-z0-9-]{4,32}$/', $id)) {
                $errors[$i][] = 'Identifier '.$key.' kosong/tidak valid.';
            }
            if (isset($seen[$id])) {
                $errors[$i][] = 'Duplikat dalam file.';
            }
            $seen[$id] = true;
            if ($type === 'users' ? User::withTrashed()->where('nip', $id)->exists() : Student::withTrashed()->where('nim', $id)->exists()) {
                $errors[$i][] = 'Identifier sudah ada di database.';
            }
            if ($type === 'students' && empty($r['program'])) {
                $errors[$i][] = 'Prodi kosong.';
            }
            if ($type === 'students' && empty($r['address'])) {
                $errors[$i][] = 'Alamat kosong.';
            }
        }

        return $errors;
    }

    public function confirm(ImportBatch $batch, array $teamMap = [], ?int $periodId = null): int
    {
        return DB::transaction(function () use ($batch, $teamMap, $periodId) {
            $b = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($b->status === 'preview', 409, 'Batch sudah diproses.');
            $errors = $this->errors($b->rows, $b->type);
            if ($errors) {
                throw ValidationException::withMessages(['import' => 'Terdapat '.count($errors).' baris bermasalah. Perbaiki file, lalu unggah ulang.']);
            }
            foreach ($teamMap as $id) {
                if ($id) {
                    abort_unless(User::role('surveyor')->where('active', true)->whereKey($id)->exists(), 422, 'Pemetaan harus ke akun surveyor aktif.');
                }
            }
            foreach ($b->rows as $r) {
                if ($b->type === 'users') {
                    $email = filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $r['email'] : null;
                    if (! $email || User::withTrashed()->where('email', $email)->exists()) {
                        $email = $r['nip'].'@pending.invalid';
                    }
                    $u = User::create(['name' => $r['name'], 'nip' => $r['nip'], 'email' => $email, 'phone' => $r['phone'], 'position' => $r['position'], 'unit' => $r['unit'], 'password' => Str::random(64), 'active' => false, 'must_change_password' => true]);
                    $u->assignRole('surveyor');

                    continue;
                }
                $p = StudyProgram::firstOrCreate(['name' => $r['program']]);
                $s = Student::create(['name' => $r['name'], 'nim' => $r['nim'], 'semester' => $r['semester'], 'study_program_id' => $p->id, 'phone' => $r['phone'], 'source_team' => $r['team'], 'source_schedule' => $r['schedule']]);
                $s->address()->create(['address' => $r['address'], 'city' => $r['city'], 'province' => 'Sulawesi Utara']);
                foreach (['father' => 'ayah', 'mother' => 'ibu'] as $prefix => $relation) {
                    $s->parents()->create(['relation' => $relation, 'occupation' => $r[$prefix.'_job'] ?? null, 'education' => $r[$prefix.'_education'] ?? null, 'phone' => $r[$prefix.'_phone'] ?? null]);
                }
                if ($uid = $teamMap[$r['team'] ?? ''] ?? null) {
                    abort_unless($periodId, 422, 'Periode wajib dipilih untuk penugasan.');
                    $team = SurveyTeam::firstOrCreate(['name' => $r['team']]);
                    $team->members()->syncWithoutDetaching([$uid]);
                    SurveyAssignment::create(['student_id' => $s->id, 'user_id' => $uid, 'survey_period_id' => $periodId, 'survey_team_id' => $team->id]);
                }
            }
            $b->update(['status' => 'imported']);
            Audit::record('import.confirmed', $b, [], ['count' => count($b->rows), 'type' => $b->type]);

            return count($b->rows);
        });
    }
}
