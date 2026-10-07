<?php

namespace App\Enums;

enum FieldMissionClaimStatus: string
{
    case OPEN = 'OPEN';
    case OBSERVATION_SUBMITTED = 'OBSERVATION_SUBMITTED';
    case ACCEPTED = 'ACCEPTED';
    case CONFLICT = 'CONFLICT';
    case UNRESOLVED = 'UNRESOLVED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
