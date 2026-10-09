<?php

namespace App\Models;

class ImportBatch extends Record
{
    protected function casts(): array
    {
        return ['rows' => 'array', 'errors' => 'array', 'mapping' => 'array'];
    }
}
