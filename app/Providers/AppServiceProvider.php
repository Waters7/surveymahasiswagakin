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
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Survey::class, SurveyPolicy::class);
        Student::observe(StudentObserver::class);
        Paginator::useTailwind();
    }
}
