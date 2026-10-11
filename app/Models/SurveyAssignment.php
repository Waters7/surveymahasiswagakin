<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SurveyAssignment extends Record
{
    protected function casts(): array
    {
        return ['scheduled_at' => 'date', 'scheduled_until' => 'date'];
    }

    public function scheduleLabel(): string
    {
        if (! $this->scheduled_at) {
            return 'Belum dijadwalkan';
        }
        if ($this->scheduled_until && ! $this->scheduled_until->isSameDay($this->scheduled_at)) {
            return $this->scheduled_at->format('d M').' – '.$this->scheduled_until->format('d M Y');
        }

        return $this->scheduled_at->format('d M Y');
    }

    public function isScheduledOn(CarbonInterface $date): bool
    {
        return $this->scheduled_at && $date->toDateString() >= $this->scheduled_at->toDateString()
            && $date->toDateString() <= ($this->scheduled_until ?? $this->scheduled_at)->toDateString();
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
