<?php

namespace App\Services\Routing;

final class JourneySearchResult
{
    /**
     * @param  list<Journey>  $journeys
     */
    public function __construct(
        public readonly array $journeys,
        public readonly ?string $noRouteCode = null,
        public readonly ?string $noRouteMessage = null,
        public readonly int $boardingCandidates = 0,
        public readonly int $alightingCandidates = 0,
    ) {}

    public function hasJourneys(): bool
    {
        return $this->journeys !== [];
    }
}
