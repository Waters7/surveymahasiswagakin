<?php

namespace App\Enums;

enum Recommendation: string
{
    case Yes = 'direkomendasikan';
    case No = 'tidak_direkomendasikan';
    case Review = 'perlu_verifikasi';
}
