<?php

namespace App\Jobs;

use App\Models\IntegrationLog;
use App\Models\TravelLink;
use App\Notifications\SurveyNotice;
use App\Services\Audit;
use App\Services\PangiClient;
use App\Services\TravelReportBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncPangiDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 40;

    public function __construct(public int $travelId, public string $hash)
    {
        $this->tries = max(1, (int) config('pangi.retry_limit', 3));
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('pangi-'.$this->travelId))->releaseAfter(15)->expireAfter(90)];
    }

    public function handle(PangiClient $client): void
    {
        $t = TravelLink::with('user.integration')->findOrFail($this->travelId);
        if ($t->draft_hash !== $this->hash || in_array($t->status, ['synced', 'locked'])) {
            return;
        }
        if (! app(TravelReportBuilder::class)->isCurrent($t)) {
            app(TravelReportBuilder::class)->invalidate($t);

            return;
        }
        try {
            $remote = $client->trip($t->user, $t->pangi_trip_id);
            if (! in_array($remote['status'] ?? '', ['active', 'approved'])) {
                throw new \RuntimeException('Perjalanan tidak aktif.');
            }
            $status = $client->status($t);
            if ($status['locked'] ?? false) {
                $t->update(['status' => 'locked']);
                $this->log($t, 'locked', 423);

                return;
            }
            $t->refresh();
            if ($t->draft_hash !== $this->hash) {
                return;
            }
            if (! app(TravelReportBuilder::class)->isCurrent($t)) {
                app(TravelReportBuilder::class)->invalidate($t);

                return;
            }
            $response = $client->push($t);
            if (in_array($response->status(), [409, 423])) {
                $t->update(['status' => 'locked']);
                $this->log($t, 'locked', $response->status());

                return;
            }
            $response->throw();
            TravelLink::whereKey($t->id)->where('draft_hash', $this->hash)->update(['status' => 'synced', 'synced_at' => now()]);
            $this->log($t, 'synced', $response->status());
            Audit::record('pangi.synced', $t);
        } catch (\Throwable $e) {
            $this->log($t, 'failed', isset($response) ? $response->status() : null);
            throw new \RuntimeException('Sinkronisasi PANGI gagal. Periksa koneksi, kepemilikan, status, dan konfigurasi token.');
        }
    }

    private function log(TravelLink $t, string $status, ?int $code): void
    {
        IntegrationLog::create(['user_id' => $t->user_id, 'travel_link_id' => $t->id, 'action' => 'report-draft', 'external_reference' => 'SG-'.$t->uuid, 'idempotency_key' => $t->idempotency_key, 'status' => $status, 'http_status' => $code, 'error_message' => $status === 'failed' ? 'Koneksi atau validasi API gagal. Token dan payload tidak dicatat.' : null, 'synced_at' => $status === 'synced' ? now() : null]);
    }

    public function failed(?\Throwable $e): void
    {
        $t = TravelLink::find($this->travelId);
        if ($t && $t->draft_hash === $this->hash) {
            $t->update(['status' => 'failed']);
            $t->user->notify(new SurveyNotice('Sinkronisasi draft PANGI gagal. Silakan periksa konfigurasi dan coba lagi.', '/pangi/'.$t->uuid));
        }
    }
}
