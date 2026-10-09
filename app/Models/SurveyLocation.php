<?php

namespace App\Models;

class SurveyLocation extends Record
{
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'accuracy' => 'float', 'captured_at' => 'datetime'];
    }
}
