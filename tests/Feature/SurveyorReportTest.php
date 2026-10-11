<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Survey;
use App\Models\SurveyAssignment;
use App\Models\SurveyPeriod;
use App\Models\User;
use App\Services\SurveyService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSurveySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SurveyorReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-16 10:30:00', 'Asia/Makassar'));
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $role = 'surveyor'): User
    {
        $user = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $user->assignRole($role);

        return $user;
    }

    private function survey(User $worker, ?SurveyPeriod $period = null): Survey
    {
        $assignment = SurveyAssignment::factory()->create(['user_id' => $worker->id, 'survey_period_id' => ($period ?? SurveyPeriod::firstOrFail())->id]);
        $this->actingAs($worker);

        return app(SurveyService::class)->start($assignment);
    }

    public function test_reports_require_login_and_report_permission(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->get(route('reports.print'))->assertRedirect(route('login'));
        $user = User::factory()->create(['active' => true, 'must_change_password' => false]);

        $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
        $this->get(route('reports.print'))->assertForbidden();
    }

    public function test_surveyors_only_see_their_results_and_cannot_select_another_surveyor(): void
    {
        $worker = $this->user();
        $other = $this->user();
        $ownSurvey = $this->survey($worker);
        $otherSurvey = $this->survey($other);

        $this->actingAs($worker)->get(route('reports.index'))->assertOk()
            ->assertSee($ownSurvey->student_snapshot['name'])->assertDontSee($otherSurvey->student_snapshot['name'])
            ->assertDontSee($other->name)->assertSee('Belum masuk cetakan');
        $this->get(route('reports.index', ['user_id' => $other->id]))->assertForbidden();
        $this->get(route('reports.print', ['user_id' => $other->id]))->assertForbidden();
    }

    #[TestWith(['super-admin'])]
    #[TestWith(['koordinator'])]
    #[TestWith(['verifikator'])]
    #[TestWith(['pimpinan'])]
    public function test_authorized_review_roles_can_see_all_surveyors(string $role): void
    {
        $first = $this->survey($this->user());
        $second = $this->survey($this->user());

        $this->actingAs($this->user($role))->get(route('reports.index'))->assertOk()
            ->assertSee($first->student_snapshot['name'])->assertSee($second->student_snapshot['name']);
    }

    public function test_surveyor_and_period_filters_exclude_other_results(): void
    {
        $worker = $this->user();
        $selected = $this->survey($worker);
        $period = SurveyPeriod::firstOrFail()->replicate();
        $period->name = 'Periode lain';
        $period->save();
        $otherPeriod = $this->survey($worker, $period);
        $otherWorker = $this->survey($this->user());

        $this->actingAs($this->user('koordinator'))->get(route('reports.index', ['user_id' => $worker->id, 'survey_period_id' => $selected->assignment->survey_period_id]))->assertOk()
            ->assertSee($selected->student_snapshot['name'])->assertDontSee($otherPeriod->student_snapshot['name'])->assertDontSee($otherWorker->student_snapshot['name']);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $worker = $this->user();

        $this->actingAs($worker)->getJson(route('reports.index', ['user_id' => 'bad', 'survey_period_id' => 9999]))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'survey_period_id']);
    }

    public function test_drafts_and_unlocked_revisions_cannot_be_printed(): void
    {
        $worker = $this->user();
        $this->survey($worker);
        $revision = $this->survey($worker);
        $revision->update(['status' => 'perlu_revisi', 'submitted_at' => now(), 'locked_at' => null]);

        $this->actingAs($worker)->getJson(route('reports.print'))->assertUnprocessable()
            ->assertJsonPath('message', 'Belum ada hasil survey terkirim yang dapat dicetak pada filter ini.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.surveyor-pdf']);
    }

    public function test_combined_pdf_contains_every_selected_locked_result_and_its_evidence(): void
    {
        Storage::fake('local');
        $this->seed(DemoSurveySeeder::class);
        $completed = Survey::where('status', 'disetujui')->firstOrFail();
        $worker = $completed->user;
        $second = $this->survey($worker);
        $second->update(['status' => 'menunggu_verifikasi', 'submitted_at' => now(), 'locked_at' => now(), 'verify_token' => Str::random(64), 'document_hash' => app(SurveyService::class)->digest($second), 'conclusion' => 'Hasil kedua yang dikirim.', 'recommendation' => 'direkomendasikan']);
        $otherWorker = $this->survey($this->user());
        $otherWorker->update(['locked_at' => now(), 'submitted_at' => now()]);
        $otherPeriod = SurveyPeriod::firstOrFail()->replicate();
        $otherPeriod->name = 'Periode lain';
        $otherPeriod->save();
        $outsidePeriod = $this->survey($worker, $otherPeriod);
        $outsidePeriod->update(['locked_at' => now(), 'submitted_at' => now()]);
        $revision = $this->survey($worker);
        $revision->update(['status' => 'perlu_revisi', 'submitted_at' => now()]);
        $proof = $completed->photos()->where('kind', 'pkh')->firstOrFail();
        $proof->update(['path' => 'private-proof.pdf', 'metadata' => ['mime' => 'application/pdf', 'original_name' => 'bukti-pkh-contoh.pdf']]);
        $captured = [];
        View::composer('reports.surveyor-bundle', function (\Illuminate\View\View $view) use (&$captured): void {
            $captured = $view->getData();
        });

        $response = $this->actingAs($this->user('super-admin'))->get(route('reports.print', ['user_id' => $worker->id, 'survey_period_id' => $completed->assignment->survey_period_id]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $audit = AuditLog::where('action', 'report.surveyor-pdf')->latest('id')->firstOrFail();
        $this->assertSame([$completed->uuid, $second->uuid], $audit->after['survey_uuids']);
        $html = $this->view('reports.surveyor-bundle', $captured);
        $html->assertSee($completed->student_snapshot['name'])->assertSee($second->student_snapshot['name'])
            ->assertDontSee($revision->student_snapshot['name'])->assertDontSee($outsidePeriod->student_snapshot['name'])
            ->assertSee('bukti-pkh-contoh.pdf')->assertSee(url('/media/'.$proof->uuid))
            ->assertSee('Bukti penerima PKH')->assertSee('Ayah CONTOH')->assertSee('Surveyor CONTOH')
            ->assertSee('Hasil kedua yang dikirim.')->assertSee('menunggu verifikasi');
        $this->assertStringContainsString('data:image/png;base64,', (string) $html);
        $this->assertStringContainsString('data:image/jpeg;base64,', (string) $html);
        $this->assertSame(1, substr_count((string) $html, '<!doctype html>'));
        foreach ($completed->criteria_snapshot as $criterion) {
            $html->assertSee($criterion['label']);
        }
    }
}
