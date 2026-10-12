<?php

namespace Tests\Feature;

use App\Services\DeploymentReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    private function configureProduction(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.demo_mode' => false,
            'app.url' => 'https://gakin.example.test', 'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'app.timezone' => 'Asia/Makassar', 'session.secure' => true, 'session.http_only' => true,
            'session.driver' => 'database', 'cache.default' => 'database',
            'database.default' => 'mysql', 'pangi.enabled' => false,
        ]);
    }

    public function test_production_configuration_passes_without_connecting_to_database(): void
    {
        $this->configureProduction();

        $checks = app(DeploymentReadiness::class)->configurationChecks();

        $this->assertNotContains(false, $checks);
        $this->assertStringNotContainsString(config('app.key'), json_encode($checks));
    }

    #[DataProvider('unsafeConfiguration')]
    public function test_reports_the_setting_that_needs_attention(string $key, mixed $value, string $label): void
    {
        $this->configureProduction();
        config([$key => $value]);

        $checks = app(DeploymentReadiness::class)->configurationChecks();

        $this->assertFalse($checks[$label]);
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function unsafeConfiguration(): array
    {
        return [
            'local environment' => ['app.env', 'local', 'APP_ENV=production'],
            'debug visible' => ['app.debug', true, 'APP_DEBUG=false'],
            'demo enabled' => ['app.demo_mode', true, 'APP_DEMO=false'],
            'plain HTTP' => ['app.url', 'http://gakin.example.test', 'APP_URL menggunakan HTTPS dan domain hosting'],
            'localhost URL' => ['app.url', 'https://localhost', 'APP_URL menggunakan HTTPS dan domain hosting'],
            'missing key' => ['app.key', '', 'APP_KEY AES-256 valid (nilai dirahasiakan)'],
            'invalid key' => ['app.key', 'base64:not-valid!', 'APP_KEY AES-256 valid (nilai dirahasiakan)'],
            'wrong timezone' => ['app.timezone', 'UTC', 'Zona waktu Asia/Makassar'],
            'insecure cookie' => ['session.secure', false, 'Session cookie aman melalui HTTPS'],
            'temporary sessions' => ['session.driver', 'array', 'Session dan cache persisten'],
            'temporary cache' => ['cache.default', 'array', 'Session dan cache persisten'],
            'demo database' => ['database.default', 'sqlite', 'Database MySQL/MariaDB'],
            'external integration enabled' => ['pangi.enabled', true, 'PANGI tetap nonaktif'],
        ];
    }

    public function test_command_returns_failure_and_hides_database_error_details(): void
    {
        $this->configureProduction();
        config(['database.default' => 'unavailable-check', 'database.connections.unavailable-check' => ['driver' => 'missing-driver', 'password' => 'secret-never-output']]);

        $this->artisan('gakin:deployment-check')
            ->expectsOutputToContain('Koneksi database / tabel aplikasi')
            ->doesntExpectOutputToContain('secret-never-output')
            ->assertFailed();
    }

    public function test_pre_migration_check_can_skip_unavailable_database(): void
    {
        $this->configureProduction();
        config(['database.connections.mysql.driver' => 'missing-driver']);

        $checks = app(DeploymentReadiness::class)->inspect(skipDatabase: true);

        $this->assertArrayNotHasKey('Koneksi database / tabel aplikasi', $checks);
        $this->assertArrayNotHasKey('Tabel aplikasi sudah tersedia', $checks);
    }
}
