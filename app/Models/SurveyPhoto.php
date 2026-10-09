<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyPhoto extends Record
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
