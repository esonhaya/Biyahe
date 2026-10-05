<?php

namespace App\Enums;

enum JourneyTrustState: string
{
    case VERIFIED = 'VERIFIED';
    case MIXED = 'MIXED';
    case UNVERIFIED = 'UNVERIFIED';

    public function rank(): int
    {
        return match ($this) {
            self::VERIFIED => 0,
            self::MIXED => 1,
            self::UNVERIFIED => 2,
        };
    }
}
