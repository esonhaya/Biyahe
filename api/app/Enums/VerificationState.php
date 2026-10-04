<?php

namespace App\Enums;

enum VerificationState: string
{
    case DRAFT = 'DRAFT';
    case UNVERIFIED = 'UNVERIFIED';
    case VERIFIED = 'VERIFIED';
    case STALE = 'STALE';
    case RETIRED = 'RETIRED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
