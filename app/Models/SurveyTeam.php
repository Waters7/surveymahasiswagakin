<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SurveyTeam extends Record
{
    protected function casts(): array
    {
        return [];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'surveyors');
    }
}
