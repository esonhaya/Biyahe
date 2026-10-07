<?php

namespace App\Enums;

enum DisruptionCategory: string
{
    case SERVICE_SUSPENDED = 'SERVICE_SUSPENDED';
    case SERVICE_REDUCED = 'SERVICE_REDUCED';
    case REROUTED = 'REROUTED';
    case STOP_UNAVAILABLE = 'STOP_UNAVAILABLE';
    case BOARDING_AREA_UNAVAILABLE = 'BOARDING_AREA_UNAVAILABLE';
    case ROAD_CLOSURE = 'ROAD_CLOSURE';
    case FLOODING = 'FLOODING';
    case EVENT = 'EVENT';
    case TRAFFIC_DISRUPTION = 'TRAFFIC_DISRUPTION';
    case TRANSFER_UNAVAILABLE = 'TRANSFER_UNAVAILABLE';
    case OTHER = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function canHardClose(): bool
    {
        return in_array($this, [
            self::SERVICE_SUSPENDED,
            self::STOP_UNAVAILABLE,
            self::BOARDING_AREA_UNAVAILABLE,
            self::TRANSFER_UNAVAILABLE,
        ], true);
    }
}
