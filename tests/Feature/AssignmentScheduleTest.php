<?php

namespace Tests\Feature;

use App\Models\SurveyAssignment;
use App\Models\TravelLink;
use App\Models\User;
use App\Services\AssignmentQuery;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Makassar'));
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function payload(SurveyAssignment $assignment): array
    {
        return ['students' => [$assignment->student_id], 'user_id' => $assignment->user_id, 'period_id' => $assignment->survey_period_id, 'scheduled_at' => '2026-10-13', 'scheduled_until' => '2026-10-14', 'assignment_reference' => 'Surat tugas uji'];
    }

    public function test_assignment_retains_full_range_and_appears_on_each_day(): void
    {
        $assignment = SurveyAssignment::factory()->create();
        $this->actingAs($this->admin())->post('/assignments', $this->payload($assignment))->assertRedirect();
        $assignment->refresh();
        $this->assertSame('2026-10-13', $assignment->scheduled_at->toDateString());
        $this->assertSame('2026-10-14', $assignment->scheduled_until->toDateString());
        $this->assertSame('Surat tugas uji', $assignment->assignment_reference);
        $this->assertTrue($assignment->isScheduledOn(today()));
        $this->get('/tasks?date=2026-10-14')->assertOk()->assertSee($assignment->student->name)->assertSee('13 Oct – 14 Oct 2026');
        $this->get('/tasks?date=2026-10-15')->assertOk()->assertDontSee($assignment->student->name);
    }

    public function test_backward_date_range_is_rejected_without_changing_assignment(): void
    {
        $assignment = SurveyAssignment::factory()->create();
        $data = $this->payload($assignment);
        $data['scheduled_until'] = '2026-10-12';
        $this->actingAs($this->admin())->post('/assignments', $data)->assertSessionHasErrors('scheduled_until');
        $this->assertSame('2026-10-15', $assignment->fresh()->scheduled_at->toDateString());
        $this->assertNull($assignment->fresh()->scheduled_until);
    }

    public function test_range_ending_outside_period_is_rejected(): void
    {
        $assignment = SurveyAssignment::factory()->create();
        $data = $this->payload($assignment);
        $data['scheduled_until'] = '2027-01-01';
        $this->actingAs($this->admin())->post('/assignments', $data)->assertStatus(422);
        $this->assertNull($assignment->fresh()->scheduled_until);
    }

    public function test_single_day_and_unscheduled_assignments_remain_supported(): void
    {
        $admin = $this->admin();
        $single = SurveyAssignment::factory()->create(['scheduled_at' => '2026-10-14']);
        $unscheduled = SurveyAssignment::factory()->create(['scheduled_at' => null]);
        $this->assertSame([$single->id], AssignmentQuery::filtered($admin, ['date' => '2026-10-14'])->pluck('id')->all());
        $this->assertSame('14 Oct 2026', $single->scheduleLabel());
        $this->assertSame('Belum dijadwalkan', $unscheduled->scheduleLabel());
        $this->assertFalse($unscheduled->isScheduledOn(today()));
    }

    public function test_travel_link_rejects_assignment_ending_after_trip(): void
    {
        $worker = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $assignment = SurveyAssignment::factory()->create(['user_id' => $worker->id, 'scheduled_at' => '2026-10-13', 'scheduled_until' => '2026-10-16']);
        $travel = TravelLink::create(['user_id' => $worker->id, 'pangi_trip_id' => '22222222-2222-4222-8222-222222222222', 'spd_number' => 'SPD-TEST', 'starts_at' => '2026-10-13', 'ends_at' => '2026-10-14', 'trip_data' => []]);
        $this->actingAs($worker)->post('/pangi/'.$travel->uuid.'/assign', ['assignments' => [$assignment->id]])->assertStatus(422);
        $this->assertNull($assignment->fresh()->travel_link_id);
    }
}
