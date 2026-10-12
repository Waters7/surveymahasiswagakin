<?php

namespace App\Services;

use App\Models\StudentAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mencari titik perkiraan alamat melalui OpenStreetMap Nominatim.
 *
 * Yang dikirim hanya nama wilayah (desa/kelurahan, kecamatan, kabupaten/kota, provinsi);
 * jaga, RT/RW, nomor rumah, jalan, dan identitas mahasiswa tidak pernah dikirim.
 */
class AddressGeocoder
{
    public const PROVINCE = 'Sulawesi Utara';

    /** Batas Sulawesi Utara termasuk Sangihe dan Talaud: barat, utara, timur, selatan. */
    private const VIEWBOX = '123.0,5.0,127.3,0.2';

    /** @var array<string, string> */
    public const PRECISION_LABELS = ['desa' => 'desa/kelurahan', 'kecamatan' => 'kecamatan', 'kabupaten' => 'kabupaten/kota'];

    private bool $hasRequested = false;

    public function locate(StudentAddress $address): bool
    {
        $region = $this->regionName((string) $address->city);
        $best = null;
        foreach ($this->placeNames((string) $address->address, $region) as $place) {
            $result = $this->search(implode(', ', array_filter([$place, $region, self::PROVINCE])));
            if ($result && (! $best || $result['rank'] > $best['rank'])) {
                $best = $result;
            }
            if ($best && $best['rank'] >= 17) {
                break;
            }
        }
        if (! $best && $region !== '') {
            $best = $this->search($region.', '.self::PROVINCE);
        }

        $address->forceFill([
            'approx_latitude' => $best['latitude'] ?? null,
            'approx_longitude' => $best['longitude'] ?? null,
            'approx_precision' => $best ? $this->precision($best['rank']) : null,
            'approx_label' => $best['label'] ?? null,
            'geocoded_at' => now(),
        ])->save();

        return $best !== null;
    }

    /**
     * Ambil nama desa/kecamatan dari alamat bebas, tanpa jaga, RT/RW, nomor, atau jalan.
     *
     * @return list<string>
     */
    public function placeNames(string $address, string $region = ''): array
    {
        $text = preg_replace('/\b(desa|ds|kelurahan|kel|kecamatan|kec|kabupaten|kab|kota|provinsi|prov)\b\.?/iu', ',', $address);
        $unit = 'jaga|lingkungan|lingk|ling|link|lk|dusun|dsn';
        $compact = fn (string $name): string => str_replace(' ', '', mb_strtolower($name));
        $names = [];
        foreach (preg_split('/[,;\/\n]+/u', (string) $text) as $part) {
            $part = preg_replace("/^\s*($unit)\b\.?\s*(\d+|[ivxlc]+|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh)?\b/iu", '', $part);
            $part = preg_replace("/\b($unit|rt|rw|no|nomor|blok|lorong|lr)\b.*$/iu", '', (string) $part);
            $part = preg_replace(['/\b(sulawesi utara|sulut|indonesia)\b/iu', '/\b\d{5}\b/u', '/\s+/u'], ['', '', ' '], (string) $part);
            $part = trim((string) $part, " .-\t");
            $isStreet = preg_match('/^(jl|jln|jalan|gg|gang|perum|perumnas|perumahan|komp|kompleks)\b/iu', $part) === 1;
            if ($isStreet || mb_strlen($part) < 3 || ctype_digit($part) || $compact($part) === $compact($region)) {
                continue;
            }
            $names[mb_strtolower($part)] = $part;
        }

        return array_slice(array_values($names), 0, 4);
    }

    public function regionName(string $city): string
    {
        $name = trim((string) preg_replace('/^(kabupaten|kab\.?|kota)\s+/iu', '', trim($city)));

        return Str::title(mb_strtolower($name));
    }

    private function precision(int $rank): string
    {
        return match (true) {
            $rank >= 17 => 'desa',
            $rank >= 13 => 'kecamatan',
            default => 'kabupaten',
        };
    }

    /** @return array{latitude: float, longitude: float, rank: int, label: string}|null */
    private function search(string $query): ?array
    {
        $result = Cache::remember('geocode:'.sha1(mb_strtolower($query)), now()->addDays(30), function () use ($query): array|false {
            if ($this->hasRequested) {
                Sleep::for(1100)->milliseconds();
            }
            $this->hasRequested = true;
            $response = Http::withUserAgent((string) config('services.nominatim.user_agent'))->acceptJson()->timeout(20)->connectTimeout(8)
                ->get(rtrim((string) config('services.nominatim.url'), '/').'/search', [
                    'q' => $query, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'id', 'layer' => 'address',
                    'viewbox' => self::VIEWBOX, 'bounded' => 1, 'accept-language' => 'id',
                ]);
            if (! $response->successful()) {
                throw new RuntimeException('Layanan Nominatim membalas HTTP '.$response->status().'. Coba lagi nanti.');
            }
            $place = $response->json('0');
            $rank = (int) ($place['place_rank'] ?? 0);
            if (! is_array($place) || $rank < 10 || ! is_numeric($place['lat'] ?? null) || ! is_numeric($place['lon'] ?? null)) {
                return false;
            }

            return [
                'latitude' => (float) $place['lat'], 'longitude' => (float) $place['lon'], 'rank' => $rank,
                'label' => implode(', ', array_slice(explode(', ', (string) ($place['display_name'] ?? '')), 0, 3)),
            ];
        });

        return $result ?: null;
    }
}
