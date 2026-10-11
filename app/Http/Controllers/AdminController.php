<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\RecommendationRule;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\SurveyAssignment;
use App\Models\SurveyFormVersion;
use App\Models\SurveyPeriod;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserIntegration;
use App\Notifications\SurveyNotice;
use App\Services\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    public function students(Request $r): View
    {
        $q = Student::with('program', 'address', 'assignments.user');
        if ($r->q) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('nim', 'like', '%'.$r->q.'%'));
        }

        return view('students', ['students' => $q->paginate(25)->withQueryString(), 'users' => User::role('surveyor')->where('active', true)->get(), 'periods' => SurveyPeriod::all(), 'programs' => StudyProgram::all()]);
    }

    public function studentEdit(Student $student): View
    {
        return view('student-edit', ['student' => $student->load('address'), 'programs' => StudyProgram::all()]);
    }

    public function studentSave(Request $r, ?Student $student = null): RedirectResponse
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'nim' => ['nullable', 'string', 'max:32', Rule::unique('students')->ignore($student?->id)], 'registration_number' => ['nullable', 'string', 'max:32', Rule::unique('students')->ignore($student?->id)], 'semester' => 'nullable|string|max:20', 'phone' => 'nullable|string|max:30', 'study_program_id' => 'required|exists:study_programs,id', 'address' => 'required|string|max:2000', 'city' => 'required|string|max:255', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180']);
        abort_unless(! empty($d['nim']) || ! empty($d['registration_number']), 422, 'NIM atau nomor pendaftaran wajib diisi.');
        DB::transaction(function () use ($d, $student) {
            $s = $student ?? new Student;
            $s->fill(collect($d)->except(['address', 'city', 'latitude', 'longitude'])->all());
            $s->save();
            $s->address()->updateOrCreate(['student_id' => $s->id], collect($d)->only(['address', 'city', 'latitude', 'longitude'])->all());
            Audit::record('student.saved', $s);
        });

        return back()->with('status', 'Data mahasiswa disimpan.');
    }

    public function assign(Request $r): RedirectResponse
    {
        $d = $r->validate(['students' => 'required|array|min:1|max:500', 'students.*' => 'required|integer|exists:students,id', 'user_id' => 'required|integer|exists:users,id', 'period_id' => 'required|integer|exists:survey_periods,id', 'scheduled_at' => 'nullable|date|required_with:scheduled_until', 'scheduled_until' => 'nullable|date|after_or_equal:scheduled_at', 'assignment_reference' => 'nullable|string|max:255']);
        $u = User::findOrFail($d['user_id']);
        abort_unless($u->active && $u->hasRole('surveyor'), 422, 'Surveyor harus aktif.');
        $p = SurveyPeriod::findOrFail($d['period_id']);
        if (! empty($d['scheduled_at'])) {
            abort_unless($d['scheduled_at'] >= $p->starts_at->toDateString() && ($d['scheduled_until'] ?? $d['scheduled_at']) <= $p->ends_at->toDateString(), 422, 'Jadwal di luar periode.');
        }
        DB::transaction(function () use ($d, $u) {
            foreach ($d['students'] as $id) {
                $a = SurveyAssignment::firstOrNew(['student_id' => $id, 'survey_period_id' => $d['period_id']]);
                abort_if($a->exists && $a->survey, 422, 'Survey sudah dimulai; penugasan tidak boleh diganti.');
                $a->fill(['user_id' => $u->id, 'scheduled_at' => $d['scheduled_at'] ?? null, 'scheduled_until' => $d['scheduled_until'] ?? null, 'assignment_reference' => $d['assignment_reference'] ?? null, 'status' => ! empty($d['scheduled_at']) ? 'dijadwalkan' : 'belum_dijadwalkan', 'travel_link_id' => null]);
                $a->save();
                Audit::record('assignment.saved', $a);
            }
            $u->notify(new SurveyNotice('Anda mendapat '.count($d['students']).' penugasan Survey GAKIN.', '/tasks'));
        });

        return back()->with('status', 'Penugasan tersimpan.');
    }

    public function users(Request $r): View
    {
        $q = User::with('roles', 'integration');
        if ($r->q) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('nip', 'like', '%'.$r->q.'%'));
        }

        return view('users', ['users' => $q->paginate(20)->withQueryString(), 'roles' => Role::pluck('name')]);
    }

    public function userSave(Request $r, ?User $user = null): RedirectResponse
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', Rule::unique('users')->ignore($user?->id)], 'nip' => ['nullable', 'regex:/^\d{18}$/', Rule::unique('users')->ignore($user?->id)], 'phone' => 'nullable|string|max:30', 'unit' => 'nullable|string|max:255', 'position' => 'nullable|string|max:255', 'role' => 'required|exists:roles,name', 'active' => 'required|boolean', 'password' => 'nullable|string|min:12|max:128', 'external_user_id' => 'nullable|uuid']);
        if (! $user || (! $user->active && $d['active'])) {
            abort_unless(! empty($d['password']), 422, 'Password awal wajib diisi.');
        }
        if ($user?->id === auth()->id()) {
            abort_unless($d['active'] && $d['role'] === 'super-admin', 422, 'Akun admin sendiri tidak boleh dinonaktifkan atau diturunkan.');
        }
        DB::transaction(function () use ($d, $user) {
            $u = $user ?? new User;
            $u->fill(collect($d)->except(['role', 'password', 'external_user_id'])->all());
            if (! empty($d['password'])) {
                $u->password = $d['password'];
                $u->must_change_password = true;
            }
            $u->save();
            $u->syncRoles([$d['role']]);
            if (! empty($d['external_user_id'])) {
                UserIntegration::updateOrCreate(['user_id' => $u->id], ['external_user_id' => $d['external_user_id'], 'nip' => $u->nip, 'email' => $u->email, 'connected_at' => now(), 'status' => 'connected']);
            }
            Audit::record('user.saved', $u, [], ['role' => $d['role'], 'active' => (bool) $d['active']]);
        });

        return back()->with('status', 'Pengguna disimpan. Password baru wajib diganti saat login.');
    }

    public function masters(): View
    {
        return view('masters', ['periods' => SurveyPeriod::with('formVersion', 'rule')->get(), 'forms' => SurveyFormVersion::with('criteria')->get(), 'programs' => StudyProgram::all(), 'settings' => SystemSetting::allValues()]);
    }

    public function masterSave(Request $r): RedirectResponse
    {
        $kind = $r->validate(['kind' => 'required|in:program,period,form,rule,settings'])['kind'];
        DB::transaction(function () use ($r, $kind) {
            switch ($kind) {
                case 'program':$d = $r->validate(['name' => 'required|string|max:255|unique:study_programs,name']);
                    StudyProgram::create($d);
                    break;
                case 'period':$d = $r->validate(['name' => 'required|string|max:255', 'academic_year' => 'required|string|max:20', 'survey_form_version_id' => 'required|exists:survey_form_versions,id', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after_or_equal:starts_at']);
                    abort_unless(SurveyFormVersion::find($d['survey_form_version_id'])->published, 422);
                    $p = SurveyPeriod::create($d);
                    RecommendationRule::create(['survey_period_id' => $p->id]);
                    break;
                case 'form':$d = $r->validate(['name' => 'required|string|max:255', 'version' => 'required|string|max:30', 'criteria' => 'required|array|min:1|max:100', 'criteria.*.label' => 'required|string|max:3000', 'criteria.*.evidence_mode' => 'required|in:required,optional,disabled', 'criteria.*.weight' => 'required|numeric|min:0|max:1000']);
                    $f = SurveyFormVersion::create(['name' => $d['name'], 'version' => $d['version'], 'published' => true]);
                    foreach ($d['criteria'] as $i => $c) {
                        $f->criteria()->create($c + ['position' => $i + 1]);
                    }break;
                case 'rule':$d = $r->validate(['survey_period_id' => 'required|exists:survey_periods,id', 'minimum_criteria' => 'nullable|integer|min:1|max:100', 'minimum_weight' => 'nullable|numeric|min:0', 'pkh_recommended' => 'required|boolean', 'auto_suggest' => 'required|boolean']);
                    RecommendationRule::updateOrCreate(['survey_period_id' => $d['survey_period_id']], $d);
                    break;
                case 'settings':$d = $r->validate(['app_name' => 'required|string|max:255', 'document_number' => 'required|string|max:100', 'document_date' => 'required|date', 'document_revision' => 'required|string|max:50', 'document_edition' => 'required|string|max:50', 'document_title' => 'required|string|max:500', 'gps_required' => 'required|boolean', 'group_photo_required' => 'required|boolean', 'signature_required' => 'required|boolean', 'watermark_enabled' => 'required|boolean']);
                    foreach ($d as $k => $v) {
                        SystemSetting::updateOrCreate(['key' => $k], ['value' => in_array($k, ['gps_required', 'group_photo_required', 'signature_required', 'watermark_enabled']) ? (bool) $v : $v]);
                    }break;
            }
            Audit::record('master.'.$kind, 'configuration');
        });

        return back()->with('status', 'Master disimpan. Survey yang sudah dimulai tetap memakai snapshot sebelumnya.');
    }

    public function roles(): View
    {
        return view('roles', ['roles' => Role::with('permissions')->get(), 'permissions' => Permission::pluck('name')]);
    }

    public function roleSave(Request $r): RedirectResponse
    {
        $d = $r->validate(['name' => 'required|regex:/^[a-z][a-z0-9-]{2,40}$/', 'permissions' => 'nullable|array', 'permissions.*' => 'required|exists:permissions,name']);
        abort_if($d['name'] === 'super-admin', 422, 'Role super-admin dilindungi.');
        $role = Role::findOrCreate($d['name'], 'web');
        $before = $role->permissions->pluck('name')->all();
        $role->syncPermissions($d['permissions'] ?? []);
        Audit::record('role.permissions_changed', $role, ['permissions' => $before], ['permissions' => $d['permissions'] ?? []]);

        return back()->with('status', 'Permission role disimpan.');
    }

    public function audit(): View
    {
        return view('audit', ['logs' => AuditLog::with('user')->latest()->paginate(40)]);
    }
}
