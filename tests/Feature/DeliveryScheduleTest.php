<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SurveyNotice;
use App\Services\DeliverySchedule;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeliveryScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-09 08:00:00', 'Asia/Makassar'));
        Http::preventStrayRequests();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $user->assignRole('super-admin');

        return $user;
    }

    public function test_admin_sees_daily_schedule_and_can_change_time(): void
    {
        $this->actingAs($this->admin())->get('/pangi')->assertOk()->assertSee('16:00 WITA')->assertSee('Integrasi otomatis ditunda');
        $this->post(route('pangi.schedule.update'), ['enabled' => 1, 'time' => '15:30'])->assertRedirect(route('pangi.index'));
        $settings = app(DeliverySchedule::class)->settings();
        $this->assertSame('15:30', $settings['time']);
        $this->assertTrue($settings['enabled']);
        $this->assertSame('Asia/Makassar', $settings['timezone']);
    }

    public function test_surveyor_can_read_but_cannot_modify_schedule(): void
    {
        $worker = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $worker->assignRole('surveyor');
        $this->actingAs($worker)->get('/pangi')->assertOk()->assertDontSee('Simpan jadwal');
        $this->post(route('pangi.schedule.update'), ['enabled' => 1, 'time' => '01:00'])->assertForbidden();
        $this->assertSame('16:00', app(DeliverySchedule::class)->settings()['time']);
    }

    public function test_invalid_time_does_not_replace_schedule(): void
    {
        app(DeliverySchedule::class)->settings();
        $this->actingAs($this->admin())->post(route('pangi.schedule.update'), ['enabled' => 1, 'time' => '25:72'])->assertSessionHasErrors(['time' => 'Waktu harus menggunakan format jam dan menit yang valid.']);
        $this->assertSame('16:00', app(DeliverySchedule::class)->settings()['time']);
    }

    public function test_due_schedule_notifies_once_per_day_without_sending_data(): void
    {
        Notification::fake();
        Http::fake();
        Queue::fake();
        config(['pangi.enabled' => true]);
        $admin = $this->admin();
        $schedule = app(DeliverySchedule::class);
        $schedule->settings();
        $this->travelTo(Carbon::parse('2026-10-09 07:59:00', 'UTC'));
        $this->assertFalse($schedule->remindDue());
        Notification::assertNothingSent();
        $this->travelTo(Carbon::parse('2026-10-09 08:00:00', 'UTC'));
        $this->artisan('gakin:delivery-reminder')->assertExitCode(0);
        $this->artisan('gakin:delivery-reminder')->assertExitCode(0);
        Notification::assertSentTo($admin, SurveyNotice::class, fn (SurveyNotice $notice): bool => str_contains($notice->message, '16:00 WITA') && $notice->url === '/pangi');
        $this->assertCount(1, Notification::sent($admin, SurveyNotice::class));
        $this->assertCount(1, $schedule->settings()['runs']);
        $this->assertSame('Menunggu integrasi', $schedule->settings()['runs'][0]['status']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_paused_schedule_has_no_next_run_or_notification(): void
    {
        Notification::fake();
        $this->actingAs($this->admin())->post(route('pangi.schedule.update'), ['enabled' => 0, 'time' => '16:00'])->assertRedirect();
        $this->travelTo(Carbon::parse('2026-10-09 16:00:00', 'Asia/Makassar'));
        $schedule = app(DeliverySchedule::class);
        $this->assertNull($schedule->nextRun());
        $this->assertFalse($schedule->remindDue());
        Notification::assertNothingSent();
        $this->assertSame([], $schedule->settings()['runs']);
    }

    public function test_new_schedule_after_deadline_starts_tomorrow(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 18:00:00', 'Asia/Makassar'));
        $schedule = app(DeliverySchedule::class);
        $this->assertSame('2026-10-10 16:00', $schedule->nextRun()->format('Y-m-d H:i'));
        $this->assertFalse($schedule->remindDue());
        $this->assertSame([], $schedule->settings()['runs']);
    }
}
