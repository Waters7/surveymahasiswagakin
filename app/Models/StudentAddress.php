<?php

namespace App\Models;

class StudentAddress extends Record
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (StudentAddress $address): void {
            if ($address->isDirty(['address', 'village', 'district', 'city', 'province'])) {
                $address->forceFill(['approx_latitude' => null, 'approx_longitude' => null, 'approx_precision' => null, 'approx_label' => null, 'geocoded_at' => null]);
            }
        });
    }

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'approx_latitude' => 'float', 'approx_longitude' => 'float', 'geocoded_at' => 'datetime'];
    }
}
