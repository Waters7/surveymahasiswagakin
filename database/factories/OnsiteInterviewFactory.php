<?php

namespace Database\Factories;

use App\Models\OnsiteInterview;
use App\Models\Student;
use App\Models\SurveyPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnsiteInterview>
 */
class OnsiteInterviewFactory extends Factory
{
    protected $model = OnsiteInterview::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'survey_period_id' => fn (): int => SurveyPeriod::firstOrFail()->id,
            'notes' => null,
        ];
    }
}
