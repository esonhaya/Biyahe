<?php

namespace App\Enums;

enum FieldLocationMethod: string
{
    case DEVICE = 'DEVICE';
    case MANUAL = 'MANUAL';
    case UNKNOWN = 'UNKNOWN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
