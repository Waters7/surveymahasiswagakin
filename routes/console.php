<?php

use App\Models\SurveyAssignment;
use App\Models\TravelLink;
use App\Models\User;
use App\Notifications\SurveyNotice;
use App\Services\ImportService;
use App\Services\TravelReportBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('gakin:preview-reference {students} {users} {--admin=}', function () {
    $u = User::where('email', $this->option('admin') ?: env('ADMIN_EMAIL'))->firstOrFail();
    auth()->login($u);
    foreach (['students', 'users'] as $type) {
        $path = $this->argument($type);
        $batch = app(ImportService::class)->preview($path, $type, basename($path));
        $this->info($type.': '.count($batch->rows).' rows, '.count($batch->errors).' errors. /imports/'.$batch->uuid);
    }
});
Artisan::command('gakin:remind', function () {
    foreach (SurveyAssignment::with('user', 'student')->whereDate('scheduled_at', today()->addDay())->get() as $a) {
        $a->user->notify(new SurveyNotice('Survey '.$a->student->name.' dijadwalkan besok.', '/tasks'));
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
