<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyAnswer extends Record
{
    protected function casts(): array
    {
        return [];
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(SurveyAnswerEvidence::class);
    }
}
