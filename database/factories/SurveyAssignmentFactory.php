<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\SurveyAssignment;
use App\Models\SurveyPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SurveyAssignmentFactory extends Factory
{
    protected $model = SurveyAssignment::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'user_id' => User::factory(),
            'survey_period_id' => SurveyPeriod::firstOrFail()->id,
            'scheduled_at' => '2026-10-15',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (SurveyAssignment $assignment) => $assignment->user->assignRole('surveyor'));
    }
}
