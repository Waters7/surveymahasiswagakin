<?php

namespace App\Models;

use App\Enums\SurveyMethod;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Survey extends Record
{
    protected function casts(): array
    {
        return ['criteria_snapshot' => 'array', 'rules_snapshot' => 'array', 'settings_snapshot' => 'array', 'student_snapshot' => 'array', 'residence' => 'array', 'pkh' => 'boolean', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'locked_at' => 'datetime', 'method' => SurveyMethod::class];
    }

    use SoftDeletes;

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SurveyAssignment::class, 'survey_assignment_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SurveyPhoto::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(SurveySignature::class);
    }

    public function location(): HasOne
    {
        return $this->hasOne(SurveyLocation::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(SurveyVerification::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SurveyStatusHistory::class);
    }

    public function travel(): BelongsTo
    {
        return $this->belongsTo(TravelLink::class, 'travel_link_id');
    }

    public function isVideoCall(): bool
    {
        return $this->method === SurveyMethod::VideoCall;
    }

    public function editable(): bool
    {
        return ! $this->locked_at && in_array($this->status, ['draft', 'sedang_disurvey', 'perlu_revisi']);
    }
}
