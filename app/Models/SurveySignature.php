<?php

namespace App\Models;

class SurveySignature extends Record
{
    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
