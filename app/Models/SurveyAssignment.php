<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SurveyAssignment extends Record
{
    protected function casts(): array
    {
        return ['scheduled_at' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SurveyPeriod::class, 'survey_period_id');
    }

    public function survey(): HasOne
    {
        return $this->hasOne(Survey::class);
    }

    public function travel(): BelongsTo
    {
        return $this->belongsTo(TravelLink::class, 'travel_link_id');
    }
}
