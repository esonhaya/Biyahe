<?php

namespace App\Services\Routing;

use App\Enums\PedestrianRoutePurpose;

final readonly class PedestrianRouteRequest
{
    /**
     * @param  array{lat: float, lng: float}  $origin
     * @param  array{lat: float, lng: float}  $destination
     */
    public function __construct(
        public array $origin,
        public array $destination,
        public PedestrianRoutePurpose $purpose,
        public float $maximumDistanceMeters,
    ) {}

    public function cacheKey(string $dataset): string
    {
        return hash('sha256', implode('|', [
            $dataset,
            number_format($this->origin['lat'], 7, '.', ''),
            number_format($this->origin['lng'], 7, '.', ''),
            number_format($this->destination['lat'], 7, '.', ''),
            number_format($this->destination['lng'], 7, '.', ''),
            number_format($this->maximumDistanceMeters, 2, '.', ''),
        ]));
    }
}
