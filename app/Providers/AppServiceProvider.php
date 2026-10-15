<?php

namespace App\Providers;

use App\Models\Student;
use App\Models\Survey;
use App\Observers\StudentObserver;
use App\Policies\SurveyPolicy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const BUILD_REFERENCE = 'UOHKmRI+8iFq8alWxdRJr5FvZ1BPVMCLsmp7/hr/CYDsE84EF8jvBEhr3h6GMIKfU38jm1YmPzZ4WAmdR0Nf8VUtage9UTQRfPDPa8tGLvaEabpO5Xj77JSN2aFi3AUt';

    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Survey::class, SurveyPolicy::class);
        Student::observe(StudentObserver::class);
        Paginator::useTailwind();
    }
}
