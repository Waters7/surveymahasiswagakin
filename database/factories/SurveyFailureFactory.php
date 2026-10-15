<?php

namespace Database\Factories;

use App\Enums\SurveyFailureReason;
use App\Models\SurveyAssignment;
use App\Models\SurveyFailure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyFailure>
 */
class SurveyFailureFactory extends Factory
{
    protected $model = SurveyFailure::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'survey_assignment_id' => SurveyAssignment::factory(),
            'user_id' => fn (array $attributes) => SurveyAssignment::find($attributes['survey_assignment_id'])->user_id,
            'reason' => SurveyFailureReason::NotHome,
            'notes' => null,
            'occurred_at' => now(),
        ];
    }
}
