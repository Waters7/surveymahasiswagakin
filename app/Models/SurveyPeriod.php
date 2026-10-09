<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SurveyPeriod extends Record
{
    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date'];
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyFormVersion::class, 'survey_form_version_id');
    }

    public function rule(): HasOne
    {
        return $this->hasOne(RecommendationRule::class);
    }
}
