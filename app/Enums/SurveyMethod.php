<?php

namespace App\Enums;

enum SurveyMethod: string
{
    case Location = 'lokasi';
    case VideoCall = 'video_call';
    case Onsite = 'onsite';

    public function label(): string
    {
        return match ($this) {
            self::Location => 'Survey lokasi',
            self::VideoCall => 'Survey video call',
            self::Onsite => 'Wawancara onsite',
        };
    }

    /**
     * Kunjungan rumah memerlukan GPS, foto kriteria, foto bersama, dan tanda tangan orang tua/wali.
     */
    public function isHomeVisit(): bool
    {
        return $this === self::Location;
    }

    /** @return list<string> */
    public function signatureRoles(): array
    {
        return $this->isHomeVisit() ? ['surveyor', 'parent'] : ['surveyor'];
    }

    /**
     * Jenis foto bukti pelaksanaan untuk survey di luar kunjungan rumah.
     */
    public function proofPhotoKind(): ?string
    {
        return match ($this) {
            self::VideoCall => 'video_call',
            self::Onsite => 'onsite',
            self::Location => null,
        };
    }

    /**
     * Wawancara onsite berasal dari daftar tanpa alamat, sehingga alamat tidak diwajibkan.
     */
    public function requiresAddress(): bool
    {
        return $this !== self::Onsite;
    }
}
