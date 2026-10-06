<?php

namespace App\Enums;

enum FieldLocationPrecision: string
{
    case POINT_ESTIMATE = 'POINT_ESTIMATE';
    case BOARDING_AREA = 'BOARDING_AREA';
    case LANDMARK = 'LANDMARK';
    case GENERAL_AREA = 'GENERAL_AREA';
    case UNKNOWN = 'UNKNOWN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
