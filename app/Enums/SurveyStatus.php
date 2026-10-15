<?php

namespace App\Enums;

enum SurveyStatus: string
{
    case Unscheduled = 'belum_dijadwalkan';
    case Scheduled = 'dijadwalkan';
    case Traveling = 'dalam_perjalanan';
    case Surveying = 'sedang_disurvey';
    case Failed = 'gagal_survey';
    case Draft = 'draft';
    case Completed = 'selesai';
    case Pending = 'menunggu_verifikasi';
    case Revision = 'perlu_revisi';
    case Approved = 'disetujui';
    case Recommended = 'direkomendasikan';
    case NotRecommended = 'tidak_direkomendasikan';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
