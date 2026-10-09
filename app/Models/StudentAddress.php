<?php

namespace App\Models;

class StudentAddress extends Record
{
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }
}
