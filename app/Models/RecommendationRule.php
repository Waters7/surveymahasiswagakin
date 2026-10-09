<?php

namespace App\Models;

class RecommendationRule extends Record
{
    protected function casts(): array
    {
        return ['pkh_recommended' => 'boolean', 'auto_suggest' => 'boolean', 'minimum_weight' => 'float'];
    }
}
