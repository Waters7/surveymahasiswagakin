<?php

namespace App\Models;

class SurveyCriterion extends Record
{
    protected function casts(): array
    {
        return ['weight' => 'float'];
    }

    protected $table = 'survey_criteria';
}
