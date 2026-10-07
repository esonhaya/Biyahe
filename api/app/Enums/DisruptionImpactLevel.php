<?php

namespace App\Enums;

enum DisruptionImpactLevel: string
{
    case INFORMATIONAL = 'INFORMATIONAL';
    case POSSIBLY_AFFECTED = 'POSSIBLY_AFFECTED';
    case AFFECTED = 'AFFECTED';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    public function rank(): int
    {
        return match ($this) {
            self::INFORMATIONAL => 0,
            self::POSSIBLY_AFFECTED => 1,
            self::AFFECTED => 2,
            self::SERVICE_UNAVAILABLE => 3,
        };
    }
}
