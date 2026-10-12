<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\Survey;
use App\Models\SurveyAssignment;
use App\Models\User;
use App\Services\SurveyService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSurveySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MapAndDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_lists_address_search_without_gps_and_hides_other_surveyor_targets(): void
    {
        $this->seed(DatabaseSeeder::class);
        $assignment = SurveyAssignment::factory()->create();
        $assignment->student->update(['name' => '<script>alert(1)</script>']);
        $assignment->student->address()->updateOrCreate(['student_id' => $assignment->student_id], ['address' => 'Jalan Contoh & Satu', 'city' => 'Manado', 'latitude' => null, 'longitude' => null]);
        $other = SurveyAssignment::factory()->create();

        $response = $this->actingAs($assignment->user)->get('/map');

        $response->assertOk()
            ->assertSee('Cari alamat di OpenStreetMap')
            ->assertSee('href="https://www.openstreetmap.org/search?query=Jalan%20Contoh%20%26%20Satu%20Manado"', false)
            ->assertSee('Belum ada GPS')
            ->assertSee('<script>alert(1)</script>')
            ->assertDontSee($other->student->name)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('google.com/maps', false);
    }

    public function test_map_uses_registered_coordinates_when_visit_gps_is_missing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $assignment = SurveyAssignment::factory()->create();
        $assignment->student->address()->update(['latitude' => 0, 'longitude' => 124.8337]);

        $response = $this->actingAs($assignment->user)->get('/map');

        $response->assertOk()
            ->assertSee('Buka OpenStreetMap')
            ->assertSee('https://www.openstreetmap.org/?mlat=0&mlon=124.8337#map=17/0/124.8337')
            ->assertSee('Lihat titik')
            ->assertDontSee('Cari alamat di OpenStreetMap');
    }

    public function test_map_offers_labelled_approximate_point_when_only_address_estimate_exists(): void
    {
        $this->seed(DatabaseSeeder::class);
        $assignment = SurveyAssignment::factory()->create();
        $assignment->student->address->forceFill(['approx_latitude' => 1.2151679, 'approx_longitude' => 124.7978881, 'approx_precision' => 'kabupaten', 'approx_label' => 'Minahasa, Sulawesi Utara, Indonesia', 'geocoded_at' => now()])->save();

        $response = $this->actingAs($assignment->user)->get('/map');

        $response->assertOk()
            ->assertSee('Titik perkiraan tingkat kabupaten/kota: Minahasa, Sulawesi Utara, Indonesia')
            ->assertSee('Lihat titik perkiraan')
            ->assertSee('Cari alamat di OpenStreetMap')
            ->assertDontSee('Buka OpenStreetMap');
    }

    public function test_map_does_not_offer_an_external_location_link_when_address_and_coordinates_are_missing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $assignment = SurveyAssignment::factory()->create();
        $assignment->student->address()->update(['address' => '', 'city' => '', 'latitude' => null, 'longitude' => null]);

        $response = $this->actingAs($assignment->user)->get('/map');

        $response->assertOk()
            ->assertSee('Lengkapi alamat terlebih dahulu.')
            ->assertDontSee('href="https://www.openstreetmap.org/search?', false)
            ->assertDontSee('href="https://www.openstreetmap.org/?mlat=', false)
            ->assertDontSee('Lihat titik');
    }

    public function test_demo_can_be_seeded_twice_without_overwriting_practice_and_has_valid_printable_output(): void
    {
        Storage::fake('local');
        $this->seed(DemoSurveySeeder::class);
        $draft = Survey::where('status', 'draft')->firstOrFail();
        $draft->update(['conclusion' => 'Latihan yang sudah diubah pengguna.']);

        $this->seed(DemoSurveySeeder::class);

        $this->assertDatabaseCount('students', 2);
        $this->assertDatabaseCount('surveys', 2);
        $this->assertSame('Latihan yang sudah diubah pengguna.', $draft->fresh()->conclusion);
        $finished = Survey::where('status', 'disetujui')->firstOrFail();
        $this->assertCount(10, $finished->answers);
        $this->assertCount(2, $finished->signatures);
        $this->assertSame([], app(SurveyService::class)->missing($finished));
        $this->assertSame($finished->document_hash, app(SurveyService::class)->digest($finished));
        Storage::disk('local')->assertExists($finished->photos->first()->path);
        $this->actingAs(User::where('nip', 'DEMO001')->firstOrFail());
        $this->get('/surveys/'.$draft->uuid)->assertOk()->assertSee('CONTOH LATIHAN')->assertSee('Data orang tua / wali');
        $this->get('/surveys/'.$finished->uuid.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/map')->assertOk()
            ->assertSee('https://www.openstreetmap.org/?mlat=1.4635&mlon=124.8338#map=17/1.4635/124.8338')
            ->assertDontSee('https://www.openstreetmap.org/?mlat=1.4634&mlon=124.8337#map=17/1.4634/124.8337');
    }

    public function test_demo_refuses_to_seed_the_normal_local_database(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        try {
            $this->seed(DemoSurveySeeder::class);
            $this->fail('Seeder should refuse a database not named gakin-demo.sqlite.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('gakin-demo.sqlite', $exception->getMessage());
            $this->assertSame(0, Student::count());
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }
}
