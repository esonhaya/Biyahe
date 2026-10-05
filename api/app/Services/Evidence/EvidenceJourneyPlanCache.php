<?php

namespace App\Services\Evidence;

use App\Models\Place;
use App\Services\Routing\JourneyPlanner;
use App\Services\Routing\JourneyPlanningContext;
use App\Services\Routing\JourneySearchResult;

final class EvidenceJourneyPlanCache
{
    /** @var array<string, JourneySearchResult> */
    private array $results = [];

    private int $plannerRuns = 0;

    private int $resultCacheHits = 0;

    private readonly JourneyPlanningContext $context;

    public function __construct(private readonly JourneyPlanner $journeyPlanner)
    {
        $this->context = new JourneyPlanningContext(
            (int) config('biyahe.evidence.priority.max_cached_patterns', 250),
        );
    }

    public function plan(
        Place $origin,
        Place $destination,
        ?float $accessRadiusMeters = null,
        ?float $transferRadiusMeters = null,
        ?int $maxTransfers = null,
    ): JourneySearchResult {
        $accessRadiusMeters = min(
            $accessRadiusMeters ?? (float) config('biyahe.routing.max_access_radius_meters', 1000),
            (float) config('biyahe.routing.max_access_radius_meters', 1000),
        );
        $transferRadiusMeters = min(
            $transferRadiusMeters ?? (float) config('biyahe.routing.max_transfer_radius_meters', 600),
            (float) config('biyahe.routing.max_transfer_radius_meters', 600),
        );
        $maxTransfers = min(
            max($maxTransfers ?? (int) config('biyahe.routing.default_max_transfers', 2), 0),
            (int) config('biyahe.routing.public_max_transfers', 2),
        );
        $key = implode(':', [
            $origin->getKey(),
            $destination->getKey(),
            sprintf('%.6F', $accessRadiusMeters),
            sprintf('%.6F', $transferRadiusMeters),
            $maxTransfers,
        ]);

        if (isset($this->results[$key])) {
            $this->resultCacheHits++;

            return $this->results[$key];
        }

        $this->plannerRuns++;
        $result = $this->journeyPlanner->plan(
            $origin,
            $destination,
            $accessRadiusMeters,
            $transferRadiusMeters,
            $maxTransfers,
            $this->context,
        );

        return $this->results[$key] = $result;
    }

    /** @return array<string, int> */
    public function metrics(): array
    {
        return [
            'planner_runs' => $this->plannerRuns,
            'result_cache_hits' => $this->resultCacheHits,
            ...$this->context->metrics(),
        ];
    }
}
