<?php

namespace App\Services;

use App\Models\TravelLink;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PangiClient
{
    private function request(User $u): PendingRequest
    {
        abort_unless($u->active && $u->can('pangi.use'), 403, 'Akses integrasi pengguna tidak aktif.');
        abort_unless(config('pangi.enabled'), 422, 'Integrasi PANGI belum diaktifkan.');
        abort_unless($u->integration?->status === 'connected', 422, 'UUID pengguna PANGI belum dipetakan.');
        $url = config('pangi.base_url');
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && config('pangi.token'), 422, 'PANGI membutuhkan HTTPS dan token server.');

        return Http::baseUrl(rtrim($url, '/').'/api/integrations/gakin')->withToken(config('pangi.token'))->withHeaders(['X-Pangi-User' => $u->integration->external_user_id])->acceptJson()->timeout(config('pangi.timeout'))->connectTimeout(8)->withoutRedirecting();
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
        abort_unless(is_array($d) && ($d['user_uuid'] ?? null) === $u->integration->external_user_id, 403, 'Perjalanan bukan milik pengguna.');

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
