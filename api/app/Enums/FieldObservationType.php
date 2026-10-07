<?php

namespace App\Enums;

enum FieldObservationType: string
{
    case ROUTE_SEEN = 'ROUTE_SEEN';
    case ROUTE_NOT_SEEN = 'ROUTE_NOT_SEEN';
    case VEHICLE_BOARDED = 'VEHICLE_BOARDED';
    case BOARDING_POINT = 'BOARDING_POINT';
    case BOARDING_LOCATION = 'BOARDING_LOCATION';
    case ALIGHTING_POINT = 'ALIGHTING_POINT';
    case ALIGHTING_LOCATION = 'ALIGHTING_LOCATION';
    case STOP_SERVED = 'STOP_SERVED';
    case STOP_NOT_SERVED = 'STOP_NOT_SERVED';
    case PASS_THROUGH = 'PASS_THROUGH';
    case DIRECTION_CUE = 'DIRECTION_CUE';
    case DIRECTION = 'DIRECTION';
    case SIGNBOARD = 'SIGNBOARD';
    case TERMINAL = 'TERMINAL';
    case SEQUENCE = 'SEQUENCE';
    case TRANSFER = 'TRANSFER';
    case FARE_PAID = 'FARE_PAID';
    case FARE_OBSERVATION = 'FARE_OBSERVATION';
    case ROUTE_ENDPOINT = 'ROUTE_ENDPOINT';
    case SERVICE_NOT_OBSERVED = 'SERVICE_NOT_OBSERVED';
    case OTHER = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
