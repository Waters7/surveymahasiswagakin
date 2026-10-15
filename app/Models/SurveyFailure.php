<?php

namespace App\Models;

use App\Enums\SurveyFailureReason;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyFailure extends Record
{
    protected function casts(): array
    {
        return ['reason' => SurveyFailureReason::class, 'evidence' => 'array', 'occurred_at' => 'datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SurveyAssignment::class, 'survey_assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alasan singkat untuk kartu tugas dan laporan perjalanan; alasan lainnya memakai catatan surveyor.
     */
    public function reasonText(): string
    {
        return $this->reason === SurveyFailureReason::Other && filled($this->notes) ? $this->notes : $this->reason->label();
    }
}
