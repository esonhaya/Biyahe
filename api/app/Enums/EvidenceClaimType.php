<?php

namespace App\Enums;

enum EvidenceClaimType: string
{
    case PLACE_LOCATION = 'PLACE_LOCATION';
    case STOP_LOCATION = 'STOP_LOCATION';
    case ROUTE_IDENTITY = 'ROUTE_IDENTITY';
    case ROUTE_PATTERN = 'ROUTE_PATTERN';
    case ROUTE_GEOMETRY = 'ROUTE_GEOMETRY';
    case PATTERN_STOP_SERVICE = 'PATTERN_STOP_SERVICE';
    case PATTERN_STOP_SEQUENCE = 'PATTERN_STOP_SEQUENCE';
    case PATTERN_STOP_BOARDING = 'PATTERN_STOP_BOARDING';
    case PATTERN_STOP_ALIGHTING = 'PATTERN_STOP_ALIGHTING';
    case FARE_GENERAL_TRADITIONAL_PUJ = 'FARE_GENERAL_TRADITIONAL_PUJ';
    case BOARDING = 'BOARDING';
    case TRANSFER = 'TRANSFER';
    case FARE = 'FARE';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
