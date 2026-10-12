<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DeploymentReadiness
{
    /** @return array<string, bool> */
    public function configurationChecks(): array
    {
        $url = parse_url((string) config('app.url'));
        $key = (string) config('app.key');
        $decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return [
            'APP_ENV=production' => config('app.env') === 'production',
            'APP_DEBUG=false' => config('app.debug') === false,
            'APP_DEMO=false' => ! config('app.demo_mode'),
            'APP_URL menggunakan HTTPS dan domain hosting' => ($url['scheme'] ?? '') === 'https' && ! empty($url['host']) && ! in_array($url['host'], ['localhost', '127.0.0.1', '::1'], true) && empty($url['user']) && empty($url['pass']),
            'APP_KEY AES-256 valid (nilai dirahasiakan)' => is_string($decodedKey) && strlen($decodedKey) === 32,
            'Zona waktu Asia/Makassar' => config('app.timezone') === 'Asia/Makassar',
            'Session cookie aman melalui HTTPS' => config('session.secure') === true && config('session.http_only') === true,
            'Session dan cache persisten' => in_array(config('session.driver'), ['database', 'file'], true) && in_array(config('cache.default'), ['database', 'file'], true),
            'Database MySQL/MariaDB' => in_array(config('database.default'), ['mysql', 'mariadb'], true),
            'PANGI tetap nonaktif' => ! config('pangi.enabled'),
        ];
    }

    /** @return array<string, bool> */
    public function inspect(bool $skipDatabase = false): array
    {
        $checks = ['PHP 64-bit >= 8.3.33' => PHP_VERSION_ID >= 80333 && PHP_INT_SIZE === 8];
        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip'] as $extension) {
            $checks['Ekstensi '.$extension] = extension_loaded($extension);
        }
        $checks += $this->configurationChecks();
        $manifest = json_decode(is_file(public_path('build/manifest.json')) ? file_get_contents(public_path('build/manifest.json')) : '{}', true);
        foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
            $asset = $manifest[$entry]['file'] ?? '';
            $checks['Build '.$entry] = $asset !== '' && is_file(public_path('build/'.$asset));
        }
        $checks['Tidak memakai Vite development server'] = ! is_file(public_path('hot'));
        foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            $checks['Storage writable: '.$directory] = is_dir(storage_path($directory)) && is_writable(storage_path($directory));
        }
        $checks['Bootstrap cache writable'] = is_dir(base_path('bootstrap/cache')) && is_writable(base_path('bootstrap/cache'));
        $privatePath = realpath(config('filesystems.disks.local.root'));
        $publicPath = realpath(public_path());
        $checks['Bukti survey disimpan di luar public'] = $privatePath !== false && $publicPath !== false && ! str_starts_with(strtolower($privatePath).DIRECTORY_SEPARATOR, strtolower($publicPath).DIRECTORY_SEPARATOR);
        if (! $skipDatabase) {
            try {
                DB::connection()->getPdo();
                $checks['Koneksi database'] = true;
                $checks['Tabel aplikasi sudah tersedia'] = Schema::hasTable('surveys') && Schema::hasTable('users') && Schema::hasTable('system_settings') && Schema::hasTable('sessions') && Schema::hasTable('cache');
            } catch (Throwable) {
                $checks['Koneksi database / tabel aplikasi'] = false;
            }
        }

        return $checks;
    }
}
