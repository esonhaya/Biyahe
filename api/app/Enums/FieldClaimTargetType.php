<?php

namespace App\Enums;

enum FieldClaimTargetType: string
{
    case PLACE = 'place';
    case STOP = 'stop';
    case ROUTE = 'route';
    case ROUTE_PATTERN = 'route_pattern';
    case PATTERN_STOP = 'route_pattern_stop';
    case TRANSFER = 'transfer_relationship';
    case FARE = 'fare';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
