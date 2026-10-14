<?php

namespace App\Enums;

enum SurveyMethod: string
{
    case Location = 'lokasi';
    case VideoCall = 'video_call';

    public function label(): string
    {
        return match ($this) {
            self::Location => 'Survey lokasi',
            self::VideoCall => 'Survey video call',
        };
    }

    /** @return list<string> */
    public function signatureRoles(): array
    {
        return $this === self::VideoCall ? ['surveyor'] : ['surveyor', 'parent'];
    }
}
