<?php

namespace App\Enums;

enum EvidenceConflictStatus: string
{
    case NONE = 'NONE';
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
