<?php

namespace App\Enums;

enum PedestrianRouteStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case PARTIAL = 'PARTIAL';
    case NO_ROUTE = 'NO_ROUTE';
    case UNAVAILABLE = 'UNAVAILABLE';
    case OUT_OF_COVERAGE = 'OUT_OF_COVERAGE';
    case DISTANCE_LIMIT = 'DISTANCE_LIMIT';

    public function isAvailable(): bool
    {
        return in_array($this, [self::AVAILABLE, self::PARTIAL], true);
    }
}
