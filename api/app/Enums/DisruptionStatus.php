<?php

namespace App\Enums;

enum DisruptionStatus: string
{
    case DRAFT = 'DRAFT';
    case SCHEDULED = 'SCHEDULED';
    case ACTIVE = 'ACTIVE';
    case RESOLVED = 'RESOLVED';
    case EXPIRED = 'EXPIRED';
    case REJECTED = 'REJECTED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
