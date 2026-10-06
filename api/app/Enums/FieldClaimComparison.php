<?php

namespace App\Enums;

enum FieldClaimComparison: string
{
    case SUPPORTS_EXISTING = 'SUPPORTS_EXISTING';
    case CONFLICTS_WITH_EXISTING = 'CONFLICTS_WITH_EXISTING';
    case ADDS_NEW_INFORMATION = 'ADDS_NEW_INFORMATION';
    case INSUFFICIENT_TO_DETERMINE = 'INSUFFICIENT_TO_DETERMINE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
