<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelLink extends Record
{
    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'trip_data' => 'array', 'draft' => 'array', 'synced_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SurveyAssignment::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(IntegrationLog::class);
    }
}
