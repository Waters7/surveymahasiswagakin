<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Student;
use App\Models\SurveyAssignment;
use App\Models\SurveyPeriod;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Notifications\SurveyNotice;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SurveyorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $user->assignRole($role);

        return $user;
    }

    private function importBatch(User $admin): ImportBatch
    {
        return ImportBatch::create([
            'user_id' => $admin->id,
            'type' => 'students',
            'filename' => 'surveyor-assignment-test.xlsx',
            'rows' => [[
                'source_row' => 4,
                'name' => 'Mahasiswa Impor Uji',
                'nim' => '711111111111',
                'program' => 'Farmasi',
                'semester' => 'I',
                'phone' => '6281111111',
                'team' => 'Surveyor pada Excel',
                'schedule' => '15-16 OKTOBER 2026',
                'address' => 'Alamat uji',
                'city' => 'Minahasa',
            ]],
            'mapping' => [],
            'errors' => [],
        ]);
    }

    public function test_assignment_uses_selected_surveyor_without_a_team(): void
    {
        $admin = $this->userWithRole('super-admin');
        $surveyor = $this->userWithRole('surveyor');
        $student = Student::factory()->create();
        $period = SurveyPeriod::firstOrFail();
        Notification::fake();

        $this->actingAs($admin)->post('/assignments', [
            'students' => [$student->id],
            'user_id' => $surveyor->id,
            'period_id' => $period->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('survey_assignments', [
            'student_id' => $student->id,
            'user_id' => $surveyor->id,
            'survey_period_id' => $period->id,
            'survey_team_id' => null,
        ]);
        $this->assertDatabaseCount('survey_teams', 0);
        Notification::assertSentTo($surveyor, SurveyNotice::class, fn (SurveyNotice $notice): bool => $notice->message === 'Anda mendapat 1 penugasan Survey GAKIN.');
    }

    public function test_reassignment_changes_surveyor_without_removing_legacy_team_data(): void
    {
        $admin = $this->userWithRole('super-admin');
        $surveyor = $this->userWithRole('surveyor');
        $legacyTeam = SurveyTeam::create(['name' => 'Nama sumber lama']);
        $assignment = SurveyAssignment::factory()->create(['survey_team_id' => $legacyTeam->id]);
        Notification::fake();

        $this->actingAs($admin)->post('/assignments', [
            'students' => [$assignment->student_id],
            'user_id' => $surveyor->id,
            'period_id' => $assignment->survey_period_id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('survey_assignments', [
            'id' => $assignment->id,
            'user_id' => $surveyor->id,
            'survey_team_id' => $legacyTeam->id,
        ]);
        $this->assertModelExists($legacyTeam);
        $this->assertDatabaseCount('surveyors', 0);
        Notification::assertSentTo($surveyor, SurveyNotice::class);
    }

    public function test_import_assigns_selected_surveyor_and_preserves_source_name_without_creating_team(): void
    {
        $admin = $this->userWithRole('super-admin');
        $surveyor = $this->userWithRole('surveyor');
        $batch = $this->importBatch($admin);
        $period = SurveyPeriod::firstOrFail();

        $this->actingAs($admin)->post('/imports/'.$batch->uuid.'/confirm', [
            'confirm' => 1,
            'period_id' => $period->id,
            'team_map' => ['Surveyor pada Excel' => $surveyor->id],
        ])->assertRedirect('/imports')->assertSessionHasNoErrors();

        $student = Student::where('nim', '711111111111')->firstOrFail();
        $this->assertSame('Surveyor pada Excel', $student->source_team);
        $this->assertDatabaseHas('survey_assignments', [
            'student_id' => $student->id,
            'user_id' => $surveyor->id,
            'survey_period_id' => $period->id,
            'survey_team_id' => null,
        ]);
        $this->assertDatabaseCount('survey_teams', 0);
        $this->assertSame('imported', $batch->fresh()->status);
    }

    public function test_import_rejects_inactive_surveyor_without_saving_students(): void
    {
        $admin = $this->userWithRole('super-admin');
        $surveyor = $this->userWithRole('surveyor');
        $surveyor->update(['active' => false]);
        $batch = $this->importBatch($admin);

        $this->actingAs($admin)->postJson('/imports/'.$batch->uuid.'/confirm', [
            'confirm' => 1,
            'period_id' => SurveyPeriod::firstOrFail()->id,
            'team_map' => ['Surveyor pada Excel' => $surveyor->id],
        ])->assertUnprocessable()->assertJsonPath('message', 'Pemetaan harus ke akun surveyor aktif.');

        $this->assertDatabaseMissing('students', ['nim' => '711111111111']);
        $this->assertDatabaseCount('survey_assignments', 0);
        $this->assertDatabaseCount('survey_teams', 0);
        $this->assertSame('preview', $batch->fresh()->status);
    }

    public function test_team_master_creation_is_no_longer_available(): void
    {
        $admin = $this->userWithRole('super-admin');

        $this->actingAs($admin)->post('/masters', [
            'kind' => 'team',
            'name' => 'Nama tim baru',
        ])->assertSessionHasErrors('kind');

        $this->assertDatabaseCount('survey_teams', 0);
    }
}
