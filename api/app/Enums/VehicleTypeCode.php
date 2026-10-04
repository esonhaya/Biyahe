<?php

namespace App\Enums;

enum VehicleTypeCode: string
{
    case JEEPNEY = 'JEEPNEY';
    case BUS = 'BUS';
    case MODERN_JEEPNEY = 'MODERN_JEEPNEY';
    case UV_EXPRESS = 'UV_EXPRESS';
    case BRT = 'BRT';
    case FERRY = 'FERRY';
    case TRAIN = 'TRAIN';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
