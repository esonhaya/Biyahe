<?php

namespace App\Services\Routing;

use App\Enums\JourneyLegType;
use App\Enums\JourneyTrustState;

final class JourneyLeg
{
    /**
     * @param  array<string, mixed>  $origin
     * @param  array<string, mixed>  $destination
     * @param  array<string, mixed>|null  $route
     * @param  array<string, mixed>|null  $pattern
     * @param  array<string, mixed>|null  $boardingStop
     * @param  array<string, mixed>|null  $alightingStop
     * @param  list<array<string, mixed>>  $provenance
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>|null  $geometry
     */
    public function __construct(
        public readonly JourneyLegType $type,
        public readonly array $origin,
        public readonly array $destination,
        public readonly ?float $distanceMeters = null,
        public readonly ?array $route = null,
        public readonly ?array $pattern = null,
        public readonly ?array $boardingStop = null,
        public readonly ?array $alightingStop = null,
        public readonly ?int $stopCount = null,
        public readonly ?int $boardingSequence = null,
        public readonly ?int $alightingSequence = null,
        public readonly ?string $boardingInstruction = null,
        public readonly ?array $boardingAssociation = null,
        public readonly ?array $alightingAssociation = null,
        public readonly ?string $driverInstruction = null,
        public readonly ?string $getOffGuidance = null,
        public readonly ?JourneyTrustState $trust = null,
        public readonly array $provenance = [],
        public readonly array $evidence = [],
        public readonly ?array $geometry = null,
        public readonly ?string $instruction = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $isWalking = in_array($this->type, [
            JourneyLegType::WALK_ACCESS,
            JourneyLegType::TRANSFER_WALK,
            JourneyLegType::WALK_EGRESS,
        ], true);

        return [
            'type' => $this->type->value,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'distance_meters' => $this->distanceMeters === null ? null : round($this->distanceMeters, 2),
            'distance_is_estimate' => $isWalking,
            'instruction' => $this->instruction,
            'route' => $this->route,
            'pattern' => $this->pattern,
            'boarding_stop' => $this->boardingStop,
            'alighting_stop' => $this->alightingStop,
            'stop_count' => $this->stopCount,
            'boarding_sequence' => $this->boardingSequence,
            'alighting_sequence' => $this->alightingSequence,
            'boarding_instruction' => $this->boardingInstruction,
            'boarding_association' => $this->boardingAssociation,
            'alighting_association' => $this->alightingAssociation,
            'driver_instruction' => $this->driverInstruction,
            'get_off_guidance' => $this->getOffGuidance,
            'trust' => $this->trust?->value,
            'provenance' => $this->provenance,
            'evidence' => $this->evidence,
            'geometry' => $this->geometry,
        ];
    }
}
