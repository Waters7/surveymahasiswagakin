<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Admin Utama', 'active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('super-admin');
    }

    private function user(string $name, string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['name' => $name, 'active' => true, 'must_change_password' => false, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_sees_filterable_user_list_with_status_and_last_login(): void
    {
        $surveyor = $this->user('Rina Surveyor', 'surveyor', ['nip' => '198001012010011001', 'must_change_password' => true]);
        $this->user('Budi Pegawai', 'verifikator', ['email' => '199001012020011002@pending.invalid', 'active' => false]);
        AuditLog::create(['user_id' => $surveyor->id, 'action' => 'auth.login', 'subject_type' => 'User', 'subject_id' => (string) $surveyor->id, 'created_at' => '2026-10-12 08:15:00']);

        $this->actingAs($this->admin)->get(route('users.index'))->assertOk()
            ->assertSee('Rina Surveyor')
            ->assertSee('<small>Wajib ganti password</small>', false)
            ->assertSee('12 Oct 2026 08:15')
            ->assertSee('<small class="text-warning">Email perlu diperbaiki</small>', false)
            ->assertSee('Email belum diisi')
            ->assertSee(route('users.edit', $surveyor), false);
        $this->get(route('users.index', ['status' => 'nonaktif']))->assertOk()->assertSee('Budi Pegawai')->assertDontSee('Rina Surveyor');
        $this->get(route('users.index', ['role' => 'surveyor', 'q' => '198001012010011001']))->assertOk()->assertSee('Rina Surveyor')->assertDontSee('Budi Pegawai');
    }

    public function test_admin_creates_user_only_with_an_initial_password(): void
    {
        $data = ['name' => 'Sari Verifikator', 'email' => 'sari@poltekkes-manado.ac.id', 'nip' => '', 'roles' => ['verifikator'], 'active' => '1'];

        $this->actingAs($this->admin)->get(route('users.create'))->assertOk()->assertSee('Password awal')->assertSee('value="surveyor" checked', false);
        $this->from(route('users.create'))->post(route('users.store'), $data)
            ->assertRedirect(route('users.create'))
            ->assertSessionHasErrors(['password' => 'Password awal wajib diisi untuk pengguna baru.']);
        $this->assertDatabaseMissing('users', ['email' => 'sari@poltekkes-manado.ac.id']);

        $this->post(route('users.store'), $data + ['password' => 'PasswordAwal123'])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('status', 'Pengguna Sari Verifikator disimpan. Password baru wajib diganti saat login berikutnya.');
        $user = User::where('email', 'sari@poltekkes-manado.ac.id')->firstOrFail();
        $this->assertTrue($user->hasRole('verifikator'));
        $this->assertTrue($user->active);
        $this->assertTrue($user->must_change_password);
    }

    public function test_activation_needs_password_and_admin_cannot_demote_own_account(): void
    {
        $inactive = $this->user('Budi Pegawai', 'surveyor', ['active' => false]);
        $payload = ['name' => 'Budi Pegawai', 'email' => $inactive->email, 'roles' => ['surveyor'], 'active' => '1'];

        $this->actingAs($this->admin)->get(route('users.edit', $inactive))->assertOk()->assertSee('value="Budi Pegawai"', false)->assertSee('Reset password');
        $this->from(route('users.edit', $inactive))->post(route('users.update', $inactive), $payload)
            ->assertRedirect(route('users.edit', $inactive))
            ->assertSessionHasErrors(['password' => 'Password awal wajib diisi untuk mengaktifkan akun.']);
        $this->assertFalse($inactive->fresh()->active);

        $this->from(route('users.edit', $this->admin))->post(route('users.update', $this->admin), ['name' => 'Admin Utama', 'email' => $this->admin->email, 'roles' => ['surveyor'], 'active' => '1'])
            ->assertSessionHasErrors('roles');
        $this->assertTrue($this->admin->fresh()->hasRole('super-admin'));
    }

    public function test_user_can_hold_several_roles_and_gets_their_combined_access(): void
    {
        $koordinator = $this->user('Yohana Koordinator', 'koordinator');
        $payload = ['name' => 'Yohana Koordinator', 'email' => $koordinator->email, 'roles' => ['koordinator', 'surveyor'], 'active' => '1'];

        $this->actingAs($this->admin)->post(route('users.update', $koordinator), $payload)->assertRedirect(route('users.index'));

        $koordinator->refresh();
        $this->assertEqualsCanonicalizing(['koordinator', 'surveyor'], $koordinator->getRoleNames()->all());
        $this->assertTrue($koordinator->can('assignments.manage'));
        $this->assertTrue($koordinator->can('surveys.work'));
        $this->get(route('users.edit', $koordinator))->assertSee('value="koordinator" checked', false)->assertSee('value="surveyor" checked', false);
        $this->get(route('users.index', ['role' => 'surveyor']))->assertSee('Yohana Koordinator');

        $this->from(route('users.edit', $koordinator))->post(route('users.update', $koordinator), ['roles' => []] + $payload)
            ->assertSessionHasErrors(['roles' => 'Pilih minimal satu peran.']);
        $this->post(route('users.update', $koordinator), ['roles' => ['surveyor', 'bukan-peran']] + $payload)->assertSessionHasErrors('roles.1');
        $this->assertEqualsCanonicalizing(['koordinator', 'surveyor'], $koordinator->fresh()->getRoleNames()->all());
    }

    public function test_admin_can_add_surveyor_role_to_own_account_but_keeps_super_admin(): void
    {
        $this->actingAs($this->admin)->get(route('users.edit', $this->admin))
            ->assertSee('value="super-admin" checked disabled', false)
            ->assertSee('<input type="hidden" name="roles[]" value="super-admin">', false);

        $this->post(route('users.update', $this->admin), ['name' => 'Admin Utama', 'email' => $this->admin->email, 'roles' => ['super-admin', 'surveyor'], 'active' => '1'])
            ->assertRedirect(route('users.index'));

        $this->assertEqualsCanonicalizing(['super-admin', 'surveyor'], $this->admin->fresh()->getRoleNames()->all());
    }

    public function test_users_without_permission_cannot_open_or_save_user_forms(): void
    {
        $surveyor = $this->user('Rina Surveyor', 'surveyor');

        $this->actingAs($surveyor)->get(route('users.create'))->assertForbidden();
        $this->get(route('users.edit', $this->admin))->assertForbidden();
        $this->post(route('users.store'), ['name' => 'Penyusup', 'email' => 'x@example.test', 'roles' => ['super-admin'], 'active' => '1', 'password' => 'PasswordAwal123'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }
}
