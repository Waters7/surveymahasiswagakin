<?php

namespace Tests\Feature;

use App\Models\SurveyAssignment;
use App\Services\SurveyService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_task_card_shows_callable_phone_number_and_whatsapp(): void
    {
        $assignment = SurveyAssignment::factory()->create();
        $assignment->student->update(['phone' => '6285312345678']);
        SurveyAssignment::factory()->create(['user_id' => $assignment->user_id]);

        $this->actingAs($assignment->user)->get('/tasks')->assertOk()
            ->assertSee('<a href="tel:+6285312345678" aria-label="Telepon 085312345678">0853-1234-5678</a>', false)
            ->assertSee('href="https://wa.me/6285312345678"', false)
            ->assertSee('Belum ada nomor HP');
    }

    public function test_survey_page_shows_phone_with_call_and_whatsapp_links(): void
    {
        $assignment = SurveyAssignment::factory()->create();
        $assignment->student->update(['phone' => '081298765432']);
        $this->actingAs($assignment->user);
        $survey = app(SurveyService::class)->start($assignment);

        $this->get('/surveys/'.$survey->uuid)->assertOk()
            ->assertSee('href="tel:+6281298765432"', false)
            ->assertSee('0812-9876-5432')
            ->assertSee('href="https://wa.me/6281298765432"', false);
    }
}
