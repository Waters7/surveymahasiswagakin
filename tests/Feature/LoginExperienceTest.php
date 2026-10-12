<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_login_identifies_the_main_application_without_demo_credentials(): void
    {
        config(['app.demo_mode' => false]);

        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('Aplikasi utama · Survey GAKIN')
            ->assertSee('akun petugas')
            ->assertDontSee('DEMO001')
            ->assertDontSee('Akun latihan:');
    }

    public function test_demo_login_identifies_practice_data_and_explains_the_demo_account(): void
    {
        config(['app.demo_mode' => true]);

        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('Ruang latihan · Data contoh')
            ->assertSee('DEMO001')
            ->assertSee('Gunakan akun latihan pada halaman ini.')
            ->assertDontSee('Aplikasi utama · Survey GAKIN');
    }

    public function test_active_user_can_login_using_a_pasted_nip(): void
    {
        $user = User::factory()->create([
            'nip' => '198001012010011001',
            'password' => 'LoginExample123',
            'active' => true,
            'must_change_password' => false,
        ]);

        $response = $this->post('/login', [
            'login' => ' '.$user->nip.' ',
            'password' => 'LoginExample123',
        ]);

        $response->assertRedirect('/')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    #[DataProvider('failedCredentials')]
    public function test_failed_login_keeps_account_status_private_and_does_not_retain_password(string $login, string $password, bool $active): void
    {
        User::factory()->create([
            'nip' => '198001012010011001',
            'password' => 'LoginExample123',
            'active' => $active,
            'must_change_password' => false,
        ]);

        $response = $this->from('/login')->post('/login', ['login' => $login, 'password' => $password]);

        $response->assertRedirect('/login')
            ->assertSessionHasErrors(['login' => 'Belum berhasil masuk. Periksa kembali email/NIP dan password Anda. Jika masih gagal, hubungi koordinator GAKIN.'])
            ->assertSessionHas('_old_input.login', $login)
            ->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.login']);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function failedCredentials(): array
    {
        return [
            'active account with wrong password' => ['198001012010011001', 'WrongPassword123', true],
            'inactive account with correct password' => ['198001012010011001', 'LoginExample123', false],
            'unknown account' => ['198001012010011002', 'LoginExample123', true],
        ];
    }
}
