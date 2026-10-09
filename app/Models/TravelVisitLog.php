<?php

namespace App\Models;

class TravelVisitLog extends Record
{
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
