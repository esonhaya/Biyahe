<?php

namespace App\Services\Routing;

use App\Enums\PedestrianRouteStatus;

final readonly class PedestrianRouteResult
{
    /**
     * @param  array{lat: float, lng: float}  $origin
     * @param  array{lat: float, lng: float}  $destination
     * @param  array<string, mixed>|null  $geometry
     * @param  list<string>  $warnings
     */
    public function __construct(
        public PedestrianRouteStatus $status,
        public array $origin,
        public array $destination,
        public ?float $distanceMeters = null,
        public ?int $estimatedDurationSeconds = null,
        public ?array $geometry = null,
        public ?string $source = null,
        public ?string $dataset = null,
        public ?float $originSnapDistanceMeters = null,
        public ?float $destinationSnapDistanceMeters = null,
        public array $warnings = [],
    ) {}

    public function totalDistanceMeters(): ?float
    {
        if ($this->distanceMeters === null) {
            return null;
        }

        return $this->distanceMeters + ($this->originSnapDistanceMeters ?? 0) + ($this->destinationSnapDistanceMeters ?? 0);
    }
}
