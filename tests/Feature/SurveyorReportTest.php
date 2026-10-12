<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Survey;
use App\Models\SurveyAssignment;
use App\Models\SurveyCriterion;
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $this->get(route('reports.results.excel'))->assertRedirect(route('login'));
        $user = User::factory()->create(['active' => true, 'must_change_password' => false]);

        $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
        $this->get(route('reports.print'))->assertForbidden();
        $this->get(route('reports.results.excel'))->assertForbidden();
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
        $this->get(route('reports.results.excel', ['user_id' => $other->id]))->assertForbidden();
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
        $this->getJson(route('reports.results.excel'))->assertUnprocessable()
            ->assertJsonPath('message', 'Belum ada hasil survey terkirim yang dapat diekspor pada filter ini.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.surveyor-excel']);
    }

    public function test_combined_pdf_contains_every_selected_locked_result_and_its_evidence(): void
    {
        Storage::fake('local');
        $this->seed(DemoSurveySeeder::class);
        $completed = Survey::where('status', 'disetujui')->firstOrFail();
        $completed->update(['started_at' => '2026-10-15 00:00:00']);
        $worker = $completed->user;
        $second = $this->survey($worker);
        $second->update(['started_at' => '2026-10-15 23:59:59', 'status' => 'menunggu_verifikasi', 'submitted_at' => now(), 'locked_at' => now(), 'verify_token' => Str::random(64), 'document_hash' => app(SurveyService::class)->digest($second), 'conclusion' => 'Hasil kedua yang dikirim.', 'recommendation' => 'direkomendasikan']);
        $otherWorker = $this->survey($this->user());
        $otherWorker->update(['locked_at' => now(), 'submitted_at' => now(), 'started_at' => '2026-10-15 12:00:00']);
        $otherPeriod = SurveyPeriod::firstOrFail()->replicate();
        $otherPeriod->name = 'Periode lain';
        $otherPeriod->save();
        $outsidePeriod = $this->survey($worker, $otherPeriod);
        $outsidePeriod->update(['locked_at' => now(), 'submitted_at' => now(), 'started_at' => '2026-10-15 12:00:00']);
        $revision = $this->survey($worker);
        $revision->update(['status' => 'perlu_revisi', 'submitted_at' => now(), 'started_at' => '2026-10-15 12:00:00']);
        $outsideDate = $this->survey($worker);
        $outsideDate->update(['locked_at' => now(), 'submitted_at' => now(), 'started_at' => '2026-10-16 00:00:00']);
        $proof = $completed->photos()->where('kind', 'pkh')->firstOrFail();
        $proof->update(['path' => 'private-proof.pdf', 'metadata' => ['mime' => 'application/pdf', 'original_name' => 'bukti-pkh-contoh.pdf']]);
        $captured = [];
        View::composer('reports.surveyor-bundle', function (\Illuminate\View\View $view) use (&$captured): void {
            $captured = $view->getData();
        });

        $response = $this->actingAs($this->user('super-admin'))->get(route('reports.print', ['user_id' => $worker->id, 'survey_period_id' => $completed->assignment->survey_period_id, 'date_from' => '2026-10-15', 'date_to' => '2026-10-15']));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $audit = AuditLog::where('action', 'report.surveyor-pdf')->latest('id')->firstOrFail();
        $this->assertSame([$completed->uuid, $second->uuid], $audit->after['survey_uuids']);
        $html = $this->view('reports.surveyor-bundle', $captured);
        $html->assertSee($completed->student_snapshot['name'])->assertSee($second->student_snapshot['name'])
            ->assertDontSee($revision->student_snapshot['name'])->assertDontSee($outsidePeriod->student_snapshot['name'])
            ->assertDontSee($outsideDate->student_snapshot['name'])->assertSee('15-10-2026 sampai 15-10-2026')
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

    public function test_visit_dates_filter_the_list_summary_and_download_links_for_the_whole_wita_day(): void
    {
        $worker = $this->user();
        $first = $this->survey($worker);
        $first->update(['started_at' => '2026-10-15 00:00:00', 'submitted_at' => now(), 'locked_at' => now()]);
        $last = $this->survey($worker);
        $last->update(['started_at' => '2026-10-15 23:59:59', 'submitted_at' => now(), 'locked_at' => now()]);
        $draft = $this->survey($worker);
        $draft->update(['started_at' => '2026-10-15 12:00:00']);
        $before = $this->survey($worker);
        $before->update(['started_at' => '2026-10-14 23:59:59']);
        $after = $this->survey($worker);
        $after->update(['started_at' => '2026-10-16 00:00:00']);
        $filters = ['user_id' => $worker->id, 'date_from' => '2026-10-15', 'date_to' => '2026-10-15'];

        $response = $this->actingAs($worker)->get(route('reports.index', $filters));

        $response->assertOk()->assertSee($first->student_snapshot['name'])->assertSee($last->student_snapshot['name'])
            ->assertSee($draft->student_snapshot['name'])->assertDontSee($before->student_snapshot['name'])->assertDontSee($after->student_snapshot['name'])
            ->assertSee(route('reports.print', $filters))->assertSee(route('reports.results.excel', $filters));
        $this->assertSame(3, $response->viewData('surveys')->total());
        $this->assertSame(2, $response->viewData('summaries')->sum('ready'));
        $this->assertSame(1, $response->viewData('summaries')->sum('unfinished'));
    }

    public function test_date_filters_with_no_matches_show_empty_totals_and_refuse_both_downloads(): void
    {
        $worker = $this->user();
        $this->survey($worker)->update(['started_at' => '2026-10-14 23:59:59', 'submitted_at' => now(), 'locked_at' => now()]);
        $filters = ['date_from' => '2026-10-15', 'date_to' => '2026-10-15'];

        $response = $this->actingAs($worker)->get(route('reports.index', $filters));

        $response->assertOk()->assertSee('Belum ada survey yang dilakukan sesuai filter.');
        $this->assertSame(0, $response->viewData('surveys')->total());
        $this->assertSame(0, $response->viewData('summaries')->sum('ready'));
        $this->getJson(route('reports.print', $filters))->assertUnprocessable();
        $this->getJson(route('reports.results.excel', $filters))->assertUnprocessable();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.surveyor-pdf']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.surveyor-excel']);
    }

    #[TestWith(['date_from', ['2026-10-15 23:59:59', '2026-10-16 00:00:00']])]
    #[TestWith(['date_to', ['2026-10-14 23:59:59', '2026-10-15 23:59:59']])]
    public function test_a_single_date_endpoint_is_optional_and_inclusive(string $filter, array $expectedDates): void
    {
        $worker = $this->user();
        foreach (['2026-10-14 23:59:59', '2026-10-15 23:59:59', '2026-10-16 00:00:00'] as $date) {
            $this->survey($worker)->update(['started_at' => $date]);
        }

        $response = $this->actingAs($worker)->get(route('reports.index', [$filter => '2026-10-15']));

        $response->assertOk();
        $this->assertSame($expectedDates, $response->viewData('surveys')->getCollection()->sortBy('started_at')->map(fn (Survey $survey): string => $survey->started_at->format('Y-m-d H:i:s'))->values()->all());
    }

    #[TestWith(['reports.index'])]
    #[TestWith(['reports.print'])]
    #[TestWith(['reports.results.excel'])]
    public function test_reversed_date_ranges_are_rejected_in_every_report_output(string $route): void
    {
        $this->actingAs($this->user())->getJson(route($route, ['date_from' => '2026-10-16', 'date_to' => '2026-10-15']))
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to'])
            ->assertJsonPath('errors.date_to.0', 'Tanggal akhir harus sama dengan atau setelah tanggal mulai.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.surveyor-excel']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.surveyor-pdf']);
    }

    #[TestWith(['date_from', 'not-a-date', 'Tanggal mulai harus berupa tanggal yang valid (YYYY-MM-DD).'])]
    #[TestWith(['date_to', '2026-02-30', 'Tanggal akhir harus berupa tanggal yang valid (YYYY-MM-DD).'])]
    public function test_date_filters_reject_invalid_calendar_dates(string $filter, string $value, string $message): void
    {
        $this->actingAs($this->user())->getJson(route('reports.index', [$filter => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors([$filter])->assertJsonPath('errors.'.$filter.'.0', $message);
    }

    public function test_results_excel_filters_completed_surveys_and_preserves_snapshot_values_as_text(): void
    {
        $worker = $this->user();
        $worker->update(['nip' => '001234567890123456']);
        $selected = $this->survey($worker);
        $snapshot = $selected->student_snapshot;
        $snapshot['name'] = '=HYPERLINK("https://example.test", "CONTOH")';
        $snapshot['nim'] = '00012345';
        $snapshot['registration_number'] = '000567';
        $snapshot['phone'] = '081234567890';
        $snapshot['program']['name'] = 'Program saat survey';
        $snapshot['address'] = ['address' => 'Alamat saat survey', 'city' => 'Kota saat survey'];
        $criteria = $selected->criteria_snapshot;
        $criteria[0]['label'] = 'Kriteria saat survey';
        $selected->update(['student_snapshot' => $snapshot, 'criteria_snapshot' => $criteria, 'started_at' => '2026-10-15 23:59:59', 'submitted_at' => '2026-10-16 10:30:00', 'locked_at' => now(), 'pkh' => true, 'status' => 'menunggu_verifikasi', 'conclusion' => '+Catatan survey', 'recommendation' => 'perlu_pertimbangan']);
        $selected->answers()->where('criterion_id', $criteria[0]['id'])->update(['answer' => 'perlu_verifikasi', 'detail' => 'Rincian asli', 'notes' => '=1+1']);
        $selected->photos()->create(['user_id' => $worker->id, 'kind' => 'pkh', 'path' => 'proof.pdf', 'sha256' => str_repeat('a', 64), 'metadata' => ['mime' => 'application/pdf']]);
        $selected->student->update(['name' => 'Nama baru', 'nim' => '999', 'phone' => '0999']);
        $selected->student->program->update(['name' => 'Program baru']);
        $selected->student->address()->updateOrCreate(['student_id' => $selected->student_id], ['address' => 'Alamat baru', 'city' => 'Kota baru']);
        SurveyCriterion::findOrFail($criteria[0]['id'])->update(['label' => 'Kriteria baru']);
        $otherPeriod = SurveyPeriod::firstOrFail()->replicate();
        $otherPeriod->name = 'Periode lain';
        $otherPeriod->save();
        $outsidePeriod = $this->survey($worker, $otherPeriod);
        $outsidePeriod->update(['started_at' => '2026-10-15 12:00:00', 'submitted_at' => now(), 'locked_at' => now()]);
        $outsideDate = $this->survey($worker);
        $outsideDate->update(['started_at' => '2026-10-16 00:00:00', 'submitted_at' => now(), 'locked_at' => now()]);
        $otherWorker = $this->survey($this->user());
        $otherWorker->update(['started_at' => '2026-10-15 12:00:00', 'submitted_at' => now(), 'locked_at' => now()]);
        $draft = $this->survey($worker);
        $draft->update(['started_at' => '2026-10-15 12:00:00']);
        $revision = $this->survey($worker);
        $revision->update(['started_at' => '2026-10-15 12:00:00', 'submitted_at' => now(), 'status' => 'perlu_revisi']);
        $filters = ['survey_period_id' => $selected->assignment->survey_period_id, 'date_from' => '2026-10-15', 'date_to' => '2026-10-15'];

        $response = $this->actingAs($worker)->get(route('reports.results.excel', $filters));

        $response->assertOk()->assertDownload('hasil-survey-gakin.xlsx');
        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        try {
            $sheet = $workbook->getActiveSheet();
            $data = $sheet->toArray(null, false, false);
            $this->assertCount(2, $data);
            $row = array_combine($data[0], $data[1]);
            $this->assertSame($selected->uuid, $row['ID survey']);
            $this->assertSame('=HYPERLINK("https://example.test", "CONTOH")', $row['Nama mahasiswa']);
            $this->assertSame('00012345', $row['NIM']);
            $this->assertSame('000567', $row['Nomor pendaftaran']);
            $this->assertSame('081234567890', $row['HP']);
            $this->assertSame('001234567890123456', $row['NIP surveyor']);
            $this->assertSame('Program saat survey', $row['Program studi']);
            $this->assertSame('Alamat saat survey', $row['Alamat']);
            $this->assertSame('Kota saat survey', $row['Kabupaten/Kota']);
            $this->assertSame('Kriteria saat survey', $row['Kriteria 1']);
            $this->assertSame('perlu verifikasi', $row['Jawaban 1']);
            $this->assertSame('Rincian asli', $row['Rincian 1']);
            $this->assertSame('=1+1', $row['Catatan 1']);
            $this->assertSame('Ya', $row['PKH']);
            $this->assertSame('1', $row['Jumlah bukti PKH']);
            $this->assertSame('+Catatan survey', $row['Kesimpulan']);
            $this->assertSame('perlu pertimbangan', $row['Rekomendasi']);
            $this->assertSame('menunggu verifikasi', $row['Status verifikasi']);
            $this->assertSame('2026-10-15 23:59:59', $row['Mulai survey (WITA)']);
            $this->assertSame('2026-10-16 10:30:00', $row['Terkirim (WITA)']);
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('E2')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('S2')->getDataType());
        } finally {
            $workbook->disconnectWorksheets();
            unlink($response->baseResponse->getFile()->getPathname());
        }
        $audit = AuditLog::where('action', 'report.surveyor-excel')->firstOrFail();
        $this->assertSame([$selected->uuid], $audit->after['survey_uuids']);
        $this->assertSame('2026-10-15', $audit->after['filters']['date_to']);
    }
}
