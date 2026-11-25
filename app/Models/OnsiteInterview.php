<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnsiteInterview extends Record
{
    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'tersedia' => 'Belum diambil',
        'diambil' => 'Diambil, belum dimulai',
        'berlangsung' => 'Sedang diwawancara',
        'selesai' => 'Selesai',
    ];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'previous_scheduled_at' => 'date', 'previous_scheduled_until' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SurveyPeriod::class, 'survey_period_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SurveyAssignment::class, 'survey_assignment_id');
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    private ?SurveyAssignment $otherAssignment = null;

    private bool $otherAssignmentLoaded = false;

    /**
     * Halaman daftar memuat penugasan lain sekaligus untuk semua baris agar tidak ada kueri per baris.
     */
    public function setOtherAssignment(?SurveyAssignment $assignment): void
    {
        $this->otherAssignment = $assignment;
        $this->otherAssignmentLoaded = true;
    }

    /**
     * Penugasan lain milik mahasiswa yang sama pada periode ini, bila mahasiswa belum diambil.
     */
    public function otherAssignment(): ?SurveyAssignment
    {
        if ($this->claimed_by) {
            return null;
        }
        if (! $this->otherAssignmentLoaded) {
            $this->setOtherAssignment(SurveyAssignment::with('survey', 'user')->where('student_id', $this->student_id)->where('survey_period_id', $this->survey_period_id)->first());
        }

        return $this->otherAssignment;
    }

    /**
     * Tahap wawancara: tersedia, diambil, berlangsung, atau selesai (survey sudah dikirim, termasuk lewat metode lain).
     */
    public function status(): string
    {
        $survey = $this->claimed_by ? $this->assignment?->survey : $this->otherAssignment()?->survey;

        return match (true) {
            $survey?->submitted_at !== null => 'selesai',
            ! $this->claimed_by => 'tersedia',
            $survey !== null => 'berlangsung',
            default => 'diambil',
        };
    }
}
