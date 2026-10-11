<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyPhoto extends Record
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function isPdf(): bool
    {
        return ($this->metadata['mime'] ?? null) === 'application/pdf';
    }

    public function label(): string
    {
        return match ($this->kind) {
            'pkh' => 'Bukti penerima PKH',
            'group' => 'Foto bersama',
            default => 'Bukti kriteria '.$this->criterion_id,
        };
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
