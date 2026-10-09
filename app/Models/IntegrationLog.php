<?php

namespace App\Models;

class IntegrationLog extends Record
{
    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }
}
