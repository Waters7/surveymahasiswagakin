<?php

namespace App\Services;

use App\Models\TravelLink;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien jalur integrasi PANGI. Pelaksana dikenali PANGI dari NIP akun GAKIN
 * (header X-Pangi-User) setelah token server diperiksa.
 */
class PangiClient
{
    private function request(User $u): PendingRequest
    {
        abort_unless($u->active && $u->can('pangi.use'), 403, 'Akses integrasi pengguna tidak aktif.');
        abort_unless(config('pangi.enabled'), 422, 'Integrasi PANGI belum diaktifkan.');
        abort_unless(filled($u->nip), 422, 'NIP akun belum diisi. PANGI mencocokkan pelaksana berdasarkan NIP.');
        $url = (string) config('pangi.base_url');
        abort_unless($this->allowedBaseUrl($url) && config('pangi.token'), 422, 'PANGI membutuhkan HTTPS dan token server.');

        return Http::baseUrl(rtrim($url, '/').'/api/integrations/gakin')->withToken(config('pangi.token'))->withHeaders(['X-Pangi-User' => $u->nip])->acceptJson()->timeout(config('pangi.timeout'))->connectTimeout(8)->withoutRedirecting();
    }

    /**
     * HTTPS wajib; HTTP hanya untuk uji PANGI di komputer sendiri saat APP_ENV=local.
     */
    public function allowedBaseUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return $scheme === 'https' || (app()->isLocal() && $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true));
    }

    public function trips(User $u): array
    {
        $r = $this->request($u)->get('/me/trips');
        $r->throw();

        return $r->json('data') ?? [];
    }

    public function trip(User $u, string $uuid): array
    {
        $r = $this->request($u)->get('/trips/'.$uuid);
        $r->throw();
        $d = $r->json('data');
        abort_unless(is_array($d) && (string) ($d['user_uuid'] ?? '') === (string) $u->nip, 403, 'Perjalanan bukan milik pengguna.');

        return $d;
    }

    public function status(TravelLink $t): array
    {
        return $this->request($t->user)->get('/trips/'.$t->pangi_trip_id.'/report-status')->throw()->json('data');
    }

    public function push(TravelLink $t): Response
    {
        return $this->request($t->user)->withHeaders(['Idempotency-Key' => $t->idempotency_key])->put('/trips/'.$t->pangi_trip_id.'/report-draft', $t->draft);
    }
}
