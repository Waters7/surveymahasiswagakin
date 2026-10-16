<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyVerification extends Record
{
    protected function casts(): array
    {
        return [];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
