<?php

namespace App\Models;

use App\Enums\SurveyMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SurveyAssignment extends Record
{
    protected function casts(): array
    {
        return ['scheduled_at' => 'date', 'scheduled_until' => 'date', 'method' => SurveyMethod::class];
    }

    public function isVideoCall(): bool
    {
        return $this->method === SurveyMethod::VideoCall;
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

    /** @var array<string, string> */
    public const RESULT_LABELS = [
        'direkomendasikan' => 'Direkomendasikan',
        'tidak_direkomendasikan' => 'Tidak direkomendasikan',
        'perlu_tinjauan' => 'Perlu tinjauan',
        'dalam_verifikasi' => 'Dalam verifikasi',
        'belum_selesai' => 'Belum selesai',
    ];

    /**
     * Hasil akhir penugasan; hanya keputusan verifikator yang dihitung sebagai hasil.
     */
    public function resultCategory(): string
    {
        $survey = $this->survey;

        return match (true) {
            $survey?->status === 'tidak_direkomendasikan' => 'tidak_direkomendasikan',
            $survey?->status === 'disetujui' => match ($survey->recommendation) {
                'direkomendasikan' => 'direkomendasikan',
                'tidak_direkomendasikan' => 'tidak_direkomendasikan',
                default => 'perlu_tinjauan',
            },
            in_array($survey?->status, ['menunggu_verifikasi', 'perlu_revisi'], true) => 'dalam_verifikasi',
            default => 'belum_selesai',
        };
    }

    public function failures(): HasMany
    {
        return $this->hasMany(SurveyFailure::class);
    }

    public function latestFailure(): HasOne
    {
        return $this->hasOne(SurveyFailure::class)->latestOfMany('occurred_at');
    }

    public function travel(): BelongsTo
    {
        return $this->belongsTo(TravelLink::class, 'travel_link_id');
    }
}
