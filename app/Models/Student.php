<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Record
{
    protected function casts(): array
    {
        return [];
    }

    use SoftDeletes;

    public function program(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class, 'study_program_id');
    }

    public function parents(): HasMany
    {
        return $this->hasMany(StudentParent::class);
    }

    public function address(): HasOne
    {
        return $this->hasOne(StudentAddress::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SurveyAssignment::class);
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }
}
