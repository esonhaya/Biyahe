<?php

namespace App\Services\Routing;

use App\Enums\JourneyTrustState;

final class Journey
{
    /**
     * @param  array<string, mixed>  $origin
     * @param  array<string, mixed>  $destination
     * @param  list<JourneyLeg>  $legs
     * @param  list<array<string, mixed>>  $provenance
     * @param  list<string>  $warnings
     * @param  list<string>  $rankingReasons
     */
    public function __construct(
        public readonly string $id,
        public readonly array $origin,
        public readonly array $destination,
        public readonly array $legs,
        public readonly int $transferCount,
        public readonly float $walkingDistanceMeters,
        public readonly int $rideStopCount,
        public readonly JourneyTrustState $trustState,
        public readonly array $provenance,
        public readonly array $warnings,
        public readonly array $rankingReasons = [],
        public readonly array $fare = [
            'status' => 'UNAVAILABLE',
            'label' => 'Fare unavailable',
            'amount' => null,
            'currency' => null,
        ],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'legs' => array_map(
                static fn (JourneyLeg $leg): array => $leg->toArray(),
                $this->legs,
            ),
            'transfer_count' => $this->transferCount,
            'ranking' => [
                'walking_distance_meters' => round($this->walkingDistanceMeters, 2),
                'ride_stop_count' => $this->rideStopCount,
                'policy' => 'trust, then transfers, then walking burden, then stop burden',
                'reasons' => $this->rankingReasons,
            ],
            'trust' => [
                'state' => $this->trustState->value,
                'warning' => $this->warnings[0] ?? null,
            ],
            'warnings' => $this->warnings,
            'fare' => $this->fare,
            'provenance' => $this->provenance,
        ];
    }

    public function withRankingReasons(array $rankingReasons): self
    {
        return new self(
            id: $this->id,
            origin: $this->origin,
            destination: $this->destination,
            legs: $this->legs,
            transferCount: $this->transferCount,
            walkingDistanceMeters: $this->walkingDistanceMeters,
            rideStopCount: $this->rideStopCount,
            trustState: $this->trustState,
            provenance: $this->provenance,
            warnings: $this->warnings,
            rankingReasons: $rankingReasons,
            fare: $this->fare,
        );
    }
}
