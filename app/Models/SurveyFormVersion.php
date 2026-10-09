<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyFormVersion extends Record
{
    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(SurveyCriterion::class)->orderBy('position');
    }
}
