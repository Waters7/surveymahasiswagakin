<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentAddress;
use App\Services\AddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AddressGeocodingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function address(string $address, string $city): StudentAddress
    {
        $student = Student::factory()->create();
        $student->address->update(['address' => $address, 'city' => $city]);

        return $student->address->fresh();
    }

    public function test_sends_only_place_names_and_stores_village_level_point(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '1.1564479', 'lon' => '124.8088245', 'place_rank' => 19, 'display_name' => 'Tumaratas, Minahasa, Sulawesi Utara, 95357, Indonesia']])]);
        $address = $this->address('Jaga 7, desa Tumaratas 2, kecamatan Langowan Barat, kabupaten Minahasa', 'KABUPATEN MINAHASA');

        $this->artisan('gakin:geocode-addresses')->assertExitCode(0);

        $address->refresh();
        $this->assertSame('desa', $address->approx_precision);
        $this->assertEqualsWithDelta(1.1564479, $address->approx_latitude, 0.0000001);
        $this->assertSame('Tumaratas, Minahasa, Sulawesi Utara', $address->approx_label);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['q'] === 'Tumaratas 2, Minahasa, Sulawesi Utara'
            && $request['layer'] === 'address' && str_starts_with($request->header('User-Agent')[0], 'SurveyGAKIN/'));
    }

    public function test_falls_back_to_regency_with_rate_limit_and_skips_processed_addresses(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
            ->push([])
            ->push([])
            ->push([['lat' => '1.2151679', 'lon' => '124.7978881', 'place_rank' => 12, 'display_name' => 'Minahasa, Sulawesi Utara, Indonesia']])]);
        $fallback = $this->address('Desa Antah, Kecamatan Berantah', 'KABUPATEN MINAHASA');
        $withoutPlace = $this->address('Jaga 3', '');

        $this->artisan('gakin:geocode-addresses')->assertExitCode(0);
        $this->artisan('gakin:geocode-addresses')->expectsOutput('Tidak ada alamat baru yang perlu dicari.')->assertExitCode(0);

        $this->assertSame('kabupaten', $fallback->fresh()->approx_precision);
        $this->assertNull($withoutPlace->fresh()->approx_latitude);
        $this->assertNotNull($withoutPlace->fresh()->geocoded_at);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_service_failure_stops_without_marking_addresses(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('Sedang sibuk', 503)]);
        $address = $this->address('Desa Waleure', 'KABUPATEN MINAHASA');

        $this->artisan('gakin:geocode-addresses')->assertExitCode(1);

        $this->assertNull($address->fresh()->geocoded_at);
    }

    public function test_changing_address_clears_approximate_point(): void
    {
        $address = $this->address('Desa Waleure', 'KABUPATEN MINAHASA');
        $address->forceFill(['approx_latitude' => 1.15, 'approx_longitude' => 124.83, 'approx_precision' => 'desa', 'approx_label' => 'Waleure', 'geocoded_at' => now()])->save();

        $address->update(['postal_code' => '95357']);
        $this->assertSame('desa', $address->fresh()->approx_precision);

        $address->update(['address' => 'Desa Noongan']);
        $this->assertNull($address->fresh()->approx_latitude);
        $this->assertNull($address->fresh()->geocoded_at);
    }

    /** @param  list<string>  $expected */
    #[DataProvider('freeTextAddresses')]
    public function test_extracts_only_place_names_from_free_text_addresses(string $address, array $expected): void
    {
        $this->assertSame($expected, app(AddressGeocoder::class)->placeNames($address, 'Minahasa'));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function freeTextAddresses(): array
    {
        return [
            'desa dan kecamatan dengan jaga' => ['Desa Waleure Jaga 5, Kecamatan Langowan Timur, Kabupaten Minahasa, Sulawesi Utara', ['Waleure', 'Langowan Timur']],
            'singkatan tanpa koma' => ['Desa Pinaesaan Kec.Tompaso Barat', ['Pinaesaan', 'Tompaso Barat']],
            'urutan terbalik' => ['Sulawesi Utara, kabupaten Minahasa, kecamatan Kawangkoan Barat, desa Tondegesan', ['Kawangkoan Barat', 'Tondegesan']],
            'jalan, nomor rumah dan kode pos dibuang' => ['Jl. Sam Ratulangi No. 10, Kel. Wenang Utara, Kec. Wenang, Kota Manado 95111', ['Wenang Utara', 'Wenang', 'Manado']],
            'lingkungan dan RT/RW dibuang' => ['Lingkungan III RT 02/RW 01, Kelurahan Kolongan', ['Kolongan']],
            'lingkungan di depan nama kelurahan' => ['Lingkungan 7 Malalayang Satu Timur', ['Malalayang Satu Timur']],
            'kabupaten bergabung dengan provinsi' => ['Likupang Dua, Minahasa Sulawesi Utara', ['Likupang Dua']],
            'hanya jaga' => ['Jaga 2', []],
        ];
    }
}
