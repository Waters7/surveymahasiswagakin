<?php

namespace App\Models;

class SystemSetting extends Record
{
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function allValues(): array
    {
        return static::pluck('value', 'key')->all();
    }
}
