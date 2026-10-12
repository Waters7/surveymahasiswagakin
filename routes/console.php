<?php

use App\Models\SurveyAssignment;
use App\Models\TravelLink;
use App\Models\User;
use App\Notifications\SurveyNotice;
use App\Services\DeliverySchedule;
use App\Services\DeploymentReadiness;
use App\Services\ImportService;
use App\Services\TravelReportBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('gakin:deployment-check {--skip-database : Periksa konfigurasi sebelum migrasi}', function (DeploymentReadiness $readiness): int {
    $checks = $readiness->inspect((bool) $this->option('skip-database'));
    $this->table(['Pemeriksaan', 'Hasil'], collect($checks)->map(fn (bool $passed, string $label): array => [$label, $passed ? 'OK' : 'PERIKSA'])->values()->all());
    $this->line('Periksa juga document root domain ke folder public, SSL, dan cron melalui cPanel. Pemeriksaan ini tidak mengubah data.');

    return in_array(false, $checks, true) ? 1 : 0;
})->purpose('Periksa kesiapan konfigurasi deployment cPanel tanpa menampilkan rahasia');

Artisan::command('gakin:preview-reference {students} {users} {--admin=}', function () {
    $u = User::where('email', $this->option('admin') ?: env('ADMIN_EMAIL'))->firstOrFail();
    auth()->login($u);
    foreach (['students', 'users'] as $type) {
        $path = $this->argument($type);
        $batch = app(ImportService::class)->preview($path, $type, basename($path));
        $this->info($type.': '.count($batch->rows).' rows, '.count($batch->errors).' errors. /imports/'.$batch->uuid);
    }
});
Artisan::command('gakin:remind', function (): void {
    $tomorrow = today()->addDay()->toDateString();
    $assignments = SurveyAssignment::with('user', 'student')
        ->whereHas('user', fn ($user) => $user->where('active', true))
        ->whereDoesntHave('survey', fn ($survey) => $survey->whereNotNull('locked_at'))
        ->where(fn ($schedule) => $schedule->whereDate('scheduled_at', $tomorrow)->orWhereDate('scheduled_until', $tomorrow))
        ->get();
    foreach ($assignments as $a) {
        $isRange = $a->scheduled_until && ! $a->scheduled_until->isSameDay($a->scheduled_at);
        $message = match (true) {
            $a->scheduled_at->toDateString() !== $tomorrow => 'Jadwal survey '.$a->student->name.' berakhir besok ('.$a->scheduleLabel().'). Selesaikan dan kirim hasilnya.',
            $isRange => 'Survey '.$a->student->name.' dijadwalkan mulai besok ('.$a->scheduleLabel().').',
            default => 'Survey '.$a->student->name.' dijadwalkan besok.',
        };
        $a->user->notify(new SurveyNotice($message, '/tasks'));
    }
});
Artisan::command('gakin:prepare-reports', function () {
    if (! config('pangi.auto_prepare')) {
        return;
    }
    foreach (TravelLink::whereNotIn('status', ['queued', 'locked'])->get() as $t) {
        $target = $t->assignments()->count();
        if ($target && $t->surveys()->whereNotNull('locked_at')->count() === $target) {
            app(TravelReportBuilder::class)->prepare($t);
        }
    }
});
Schedule::command('gakin:remind')->dailyAt('16:00')->timezone('Asia/Makassar')->withoutOverlapping();
Schedule::command('gakin:prepare-reports')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('gakin:delivery-reminder', function (DeliverySchedule $schedule): void {
    $this->info($schedule->remindDue() ? 'Pengingat jadwal dicatat. Tidak ada data dikirim keluar.' : 'Belum ada jadwal baru yang jatuh tempo.');
});
Schedule::command('gakin:delivery-reminder')->everyMinute()->withoutOverlapping();
