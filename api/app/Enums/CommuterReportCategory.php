<?php

namespace App\Enums;

enum CommuterReportCategory: string
{
    case ROUTE_NOT_SEEN = 'ROUTE_NOT_SEEN';
    case SERVICE_STOPPED = 'SERVICE_STOPPED';
    case REROUTE = 'REROUTE';
    case STOP_BLOCKED = 'STOP_BLOCKED';
    case FLOODING = 'FLOODING';
    case ROAD_CLOSURE = 'ROAD_CLOSURE';
    case TRANSFER_PROBLEM = 'TRANSFER_PROBLEM';
    case FARE_OBSERVATION = 'FARE_OBSERVATION';
    case OTHER = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
