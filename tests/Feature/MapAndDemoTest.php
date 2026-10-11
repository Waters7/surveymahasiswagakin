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

        $response->assertOk()->assertSee('Buka Google Maps')->assertSee('query=Jalan%20Contoh%20%26%20Satu%20Manado', false)->assertSee('Belum ada GPS')->assertDontSee($other->student->name)->assertDontSee('<script>alert(1)</script>', false);
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
        $this->get('/map')->assertOk()->assertSee('query=1.4635%2C124.8338', false);
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
