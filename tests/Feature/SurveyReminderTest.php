<?php

namespace Tests\Feature;

use App\Models\SurveyAssignment;
use App\Models\User;
use App\Notifications\SurveyNotice;
use App\Services\SurveyService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SurveyReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 16:00:00', 'Asia/Makassar'));
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
    }

    /** @param  array<string, mixed>  $attributes */
    private function assignment(array $attributes, bool $active = true): SurveyAssignment
    {
        $surveyor = User::factory()->create(['active' => $active, 'must_change_password' => false]);

        return SurveyAssignment::factory()->create(['user_id' => $surveyor->id, ...$attributes]);
    }

    /** @return list<string> */
    private function messagesFor(SurveyAssignment $assignment): array
    {
        return Notification::sent($assignment->user, SurveyNotice::class)->map(fn (SurveyNotice $notice): string => $notice->message)->all();
    }

    public function test_reminds_visits_starting_tomorrow_and_ranges_ending_tomorrow(): void
    {
        $single = $this->assignment(['scheduled_at' => '2026-10-15']);
        $rangeStart = $this->assignment(['scheduled_at' => '2026-10-15', 'scheduled_until' => '2026-10-17']);
        $rangeEnd = $this->assignment(['scheduled_at' => '2026-10-13', 'scheduled_until' => '2026-10-15']);
        $midRange = $this->assignment(['scheduled_at' => '2026-10-13', 'scheduled_until' => '2026-10-16']);

        $this->artisan('gakin:remind')->assertExitCode(0);

        $this->assertSame(['Survey '.$single->student->name.' dijadwalkan besok.'], $this->messagesFor($single));
        $this->assertSame(['Survey '.$rangeStart->student->name.' dijadwalkan mulai besok (15 Oct – 17 Oct 2026).'], $this->messagesFor($rangeStart));
        $this->assertSame(['Jadwal survey '.$rangeEnd->student->name.' berakhir besok (13 Oct – 15 Oct 2026). Selesaikan dan kirim hasilnya.'], $this->messagesFor($rangeEnd));
        $this->assertSame([], $this->messagesFor($midRange));
    }

    public function test_skips_submitted_surveys_and_inactive_surveyors_but_reminds_unfinished_work(): void
    {
        $submitted = $this->assignment(['scheduled_at' => '2026-10-15']);
        $this->actingAs($submitted->user);
        app(SurveyService::class)->start($submitted)->forceFill(['status' => 'menunggu_verifikasi', 'submitted_at' => now(), 'locked_at' => now()])->save();
        $inactive = $this->assignment(['scheduled_at' => '2026-10-15'], active: false);
        $unfinished = $this->assignment(['scheduled_at' => '2026-10-13', 'scheduled_until' => '2026-10-15']);
        $this->actingAs($unfinished->user);
        app(SurveyService::class)->start($unfinished);

        $this->artisan('gakin:remind')->assertExitCode(0);

        Notification::assertNotSentTo([$submitted->user, $inactive->user], SurveyNotice::class);
        $this->assertCount(1, $this->messagesFor($unfinished));
    }
}
