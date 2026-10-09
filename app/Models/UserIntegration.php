<?php

namespace App\Models;

class UserIntegration extends Record
{
    protected function casts(): array
    {
        return ['connected_at' => 'datetime'];
    }
}
