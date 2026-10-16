<?php

namespace App\Models;

class SurveyLocation extends Record
{
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'accuracy' => 'float', 'captured_at' => 'datetime'];
    }

    public function isPickedOnMap(): bool
    {
        return $this->source === 'peta';
    }

    /**
     * Keterangan sumber titik untuk hasil survey dan PDF.
     */
    public function sourceLabel(): string
    {
        return $this->isPickedOnMap() ? 'dipilih surveyor di peta' : 'GPS perangkat, akurasi '.($this->accuracy ?? '—').' m';
    }
}
