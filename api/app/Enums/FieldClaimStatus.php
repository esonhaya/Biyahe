<?php

namespace App\Enums;

enum FieldClaimStatus: string
{
    case PROPOSED = 'PROPOSED';
    case ATTACHED = 'ATTACHED';
    case REJECTED = 'REJECTED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
