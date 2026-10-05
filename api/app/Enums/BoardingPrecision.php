<?php

namespace App\Enums;

enum BoardingPrecision: string
{
    case EXACT_BOARDING_POINT = 'EXACT_BOARDING_POINT';
    case BOARDING_AREA = 'BOARDING_AREA';
    case APPROXIMATE_LOCATION = 'APPROXIMATE_LOCATION';
    case UNKNOWN = 'UNKNOWN';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
