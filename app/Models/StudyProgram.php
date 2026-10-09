<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyProgram extends Record
{
    protected function casts(): array
    {
        return [];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
