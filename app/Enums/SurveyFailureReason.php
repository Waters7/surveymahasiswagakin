<?php

namespace App\Enums;

enum SurveyFailureReason: string
{
    case Unreachable = 'tidak_dapat_dihubungi';
    case NotHome = 'tidak_di_rumah';
    case Other = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Unreachable => 'Tidak dapat dihubungi',
            self::NotHome => 'Tidak di rumah',
            self::Other => 'Alasan lainnya',
        };
    }
}
