<?php

namespace App\Services\Routing;

use App\Enums\JourneyLegType;
use App\Enums\JourneyTrustState;
use App\Enums\VerificationState;
use App\Models\Place;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Models\TransferRelationship;
use App\Services\Evidence\EvidenceFreshnessPolicy;
use App\Support\Geo;
use App\Support\Trust;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class JourneyPlanner
{
    public function __construct(
        private readonly NearbyStopFinder $nearbyStopFinder,
        private readonly JourneyRanker $journeyRanker,
    ) {}

    public function plan(
        Place $origin,
        Place $destination,
        ?float $accessRadiusMeters = null,
        ?float $transferRadiusMeters = null,
        ?int $maxTransfers = null,
    ): JourneySearchResult {
        $accessRadiusMeters ??= (float) config('biyahe.routing.max_access_radius_meters', 1000);
        $transferRadiusMeters ??= (float) config('biyahe.routing.max_transfer_radius_meters', 600);
        $maxTransfers ??= (int) config('biyahe.routing.default_max_transfers', 2);
        $maxTransfers = min(
            max($maxTransfers, 0),
            (int) config('biyahe.routing.public_max_transfers', 2),
        );

        $boardingStops = $this->nearbyStopFinder->find(
            $origin,
            min($accessRadiusMeters, (float) config('biyahe.routing.max_access_radius_meters', 1000)),
            (int) config('biyahe.routing.default_candidate_limit', 12),
            true,
        );
        $alightingStops = $this->nearbyStopFinder->find(
            $destination,
            min($accessRadiusMeters, (float) config('biyahe.routing.max_access_radius_meters', 1000)),
            (int) config('biyahe.routing.default_candidate_limit', 12),
            false,
        );

        if ($boardingStops->isEmpty()) {
            return new JourneySearchResult(
                journeys: [],
                noRouteCode: 'NO_NEARBY_BOARDING',
                noRouteMessage: 'No supported boarding point is currently mapped near the starting place.',
                alightingCandidates: $alightingStops->count(),
            );
        }

        if ($alightingStops->isEmpty()) {
            return new JourneySearchResult(
                journeys: [],
                noRouteCode: 'NO_NEARBY_ALIGHTING',
                noRouteMessage: 'No supported alighting point is currently mapped near the destination.',
                boardingCandidates: $boardingStops->count(),
            );
        }

        $directJourneys = $this->findDirectJourneys($origin, $destination, $boardingStops, $alightingStops);
        $journeys = array_merge(
            $directJourneys,
            $this->findBoundedTransferJourneys(
                $origin,
                $destination,
                $boardingStops,
                $alightingStops,
                min($transferRadiusMeters, (float) config('biyahe.routing.max_transfer_radius_meters', 600)),
                $maxTransfers,
            ),
        );

        $journeys = $this->deduplicate($journeys);
        if ($journeys === []) {
            $hasExcludedData = $this->hasExcludedRoutingData(
                $origin->city_id,
                $boardingStops->modelKeys(),
                $alightingStops->modelKeys(),
            );

            return new JourneySearchResult(
                journeys: [],
                noRouteCode: $hasExcludedData ? 'ROUTING_DATA_NOT_READY' : 'NO_KNOWN_JOURNEY',
                noRouteMessage: $hasExcludedData
                    ? 'Biyahe knows transport data near these places, but its service details are not ready to guide this journey yet.'
                    : 'Biyahe does not currently know a supported journey within the configured transfer limit. Coverage is still incomplete.',
                boardingCandidates: $boardingStops->count(),
                alightingCandidates: $alightingStops->count(),
            );
        }

        return new JourneySearchResult(
            journeys: $this->journeyRanker->rank($journeys),
            boardingCandidates: $boardingStops->count(),
            alightingCandidates: $alightingStops->count(),
        );
    }

    /** @return list<Journey> */
    private function findDirectJourneys(
        Place $origin,
        Place $destination,
        Collection $boardingStops,
        Collection $alightingStops,
    ): array {
        $boardingIds = $boardingStops->modelKeys();
        $alightingIds = $alightingStops->modelKeys();
        $patterns = $this->candidatePatterns($origin->city_id, $boardingIds, $alightingIds);
        $boardingById = $boardingStops->keyBy(fn (Stop $stop): int => $stop->getKey());
        $alightingById = $alightingStops->keyBy(fn (Stop $stop): int => $stop->getKey());
        $journeys = [];

        foreach ($patterns as $pattern) {
            foreach ($pattern->patternStops as $boardingPatternStop) {
                if (! Trust::isFactRoutable($boardingPatternStop, ['service', 'sequence', 'boarding']) || ! $boardingPatternStop->boarding_allowed) {
                    continue;
                }

                $boardingStop = $boardingById->get($boardingPatternStop->stop_id);
                if (! $boardingStop instanceof Stop) {
                    continue;
                }

                foreach ($pattern->patternStops as $alightingPatternStop) {
                    if (
                        ! Trust::isFactRoutable($alightingPatternStop, ['service', 'sequence', 'alighting'])
                        || ! $alightingPatternStop->alighting_allowed
                        || $alightingPatternStop->sequence <= $boardingPatternStop->sequence
                    ) {
                        continue;
                    }

                    $alightingStop = $alightingById->get($alightingPatternStop->stop_id);
                    if (! $alightingStop instanceof Stop) {
                        continue;
                    }

                    $journeys[] = $this->buildJourney(
                        id: sprintf('direct-%d-%d-%d', $pattern->getKey(), $boardingStop->getKey(), $alightingStop->getKey()),
                        origin: $origin,
                        destination: $destination,
                        legs: [
                            $this->buildWalkLeg(
                                JourneyLegType::WALK_ACCESS,
                                $origin,
                                $boardingStop,
                                $this->distanceFromPlace($origin, $boardingStop),
                            ),
                            $this->buildRideLeg(
                                $pattern,
                                $boardingPatternStop,
                                $alightingPatternStop,
                                $this->cueForPlace($destination),
                            ),
                            $this->buildWalkLeg(
                                JourneyLegType::WALK_EGRESS,
                                $alightingStop,
                                $destination,
                                $this->distanceFromPlace($destination, $alightingStop),
                            ),
                        ],
                        models: [
                            $origin,
                            $destination,
                            $boardingStop,
                            $alightingStop,
                            $pattern,
                            $pattern->route,
                            $boardingPatternStop,
                            $alightingPatternStop,
                        ],
                    );
                    if (count($journeys) >= $this->generatedJourneyLimit()) {
                        return $journeys;
                    }
                }
            }
        }

        return $journeys;
    }

    /** @return list<Journey> */
    private function findBoundedTransferJourneys(
        Place $origin,
        Place $destination,
        Collection $boardingStops,
        Collection $alightingStops,
        float $maxTransferRadiusMeters,
        int $maxTransfers,
    ): array {
        if ($maxTransfers < 1) {
            return [];
        }

        $journeys = $this->findTransferJourneys(
            $origin,
            $destination,
            $boardingStops,
            $alightingStops,
            $maxTransferRadiusMeters,
        );

        if ($maxTransfers < 2) {
            return $journeys;
        }

        return array_merge(
            $journeys,
            $this->findMultiTransferJourneys(
                $origin,
                $destination,
                $boardingStops,
                $alightingStops,
                $maxTransferRadiusMeters,
                $maxTransfers,
            ),
        );
    }

    /** @return list<Journey> */
    private function findTransferJourneys(
        Place $origin,
        Place $destination,
        Collection $boardingStops,
        Collection $alightingStops,
        float $maxTransferRadiusMeters,
    ): array {
        $firstPatterns = $this->candidatePatterns($origin->city_id, $boardingStops->modelKeys());
        $secondPatterns = $this->candidatePatterns($origin->city_id, [], $alightingStops->modelKeys(), false);
        if ($firstPatterns->isEmpty() || $secondPatterns->isEmpty()) {
            return [];
        }

        $firstStopIds = $firstPatterns
            ->flatMap(fn (RoutePattern $pattern): Collection => $pattern->patternStops->pluck('stop_id'))
            ->unique()
            ->values()
            ->all();
        $secondStopIds = $secondPatterns
            ->flatMap(fn (RoutePattern $pattern): Collection => $pattern->patternStops->pluck('stop_id'))
            ->unique()
            ->values()
            ->all();
        $relationships = TransferRelationship::query()
            ->with(['provenanceSource', 'fromStop.provenanceSource', 'fromStop.boardingProvenanceSource', 'toStop.provenanceSource', 'toStop.boardingProvenanceSource'])
            ->where('city_id', $origin->city_id)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [
                VerificationState::VERIFIED->value,
                VerificationState::UNVERIFIED->value,
            ])
            ->whereIn('review_status', ['SUPPORTED', 'PENDING_REVIEW'])
            ->whereIn('from_stop_id', $firstStopIds)
            ->whereIn('to_stop_id', $secondStopIds)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (TransferRelationship $relationship): string => $relationship->from_stop_id.':'.$relationship->to_stop_id);
        $boardingById = $boardingStops->keyBy(fn (Stop $stop): int => $stop->getKey());
        $alightingById = $alightingStops->keyBy(fn (Stop $stop): int => $stop->getKey());
        $journeys = [];

        foreach ($firstPatterns as $firstPattern) {
            foreach ($firstPattern->patternStops as $firstBoardingPatternStop) {
                if (! Trust::isFactRoutable($firstBoardingPatternStop, ['service', 'sequence', 'boarding']) || ! $firstBoardingPatternStop->boarding_allowed) {
                    continue;
                }
                $boardingStop = $boardingById->get($firstBoardingPatternStop->stop_id);
                if (! $boardingStop instanceof Stop) {
                    continue;
                }

                foreach ($firstPattern->patternStops as $firstAlightingPatternStop) {
                    if (
                        ! Trust::isFactRoutable($firstAlightingPatternStop, ['service', 'sequence', 'alighting'])
                        || ! $firstAlightingPatternStop->alighting_allowed
                        || $firstAlightingPatternStop->sequence <= $firstBoardingPatternStop->sequence
                    ) {
                        continue;
                    }
                    $firstAlightingStop = $firstAlightingPatternStop->stop;
                    if (! $firstAlightingStop instanceof Stop || ! Trust::isRoutable($firstAlightingStop)) {
                        continue;
                    }

                    foreach ($secondPatterns as $secondPattern) {
                        if (
                            $firstPattern->getKey() === $secondPattern->getKey()
                            || $firstPattern->route_id === $secondPattern->route_id
                        ) {
                            continue;
                        }

                        foreach ($secondPattern->patternStops as $secondBoardingPatternStop) {
                            if (! Trust::isFactRoutable($secondBoardingPatternStop, ['service', 'sequence', 'boarding']) || ! $secondBoardingPatternStop->boarding_allowed) {
                                continue;
                            }
                            $secondBoardingStop = $secondBoardingPatternStop->stop;
                            if (! $secondBoardingStop instanceof Stop || ! Trust::isRoutable($secondBoardingStop)) {
                                continue;
                            }

                            $relationship = null;
                            $transferDistance = 0.0;
                            if ($firstAlightingStop->getKey() === $secondBoardingStop->getKey()) {
                                $transferDistance = 0.0;
                            } else {
                                $relationship = $relationships->get($firstAlightingStop->getKey().':'.$secondBoardingStop->getKey());
                                if (! $relationship instanceof TransferRelationship) {
                                    continue;
                                }
                                if (($relationship->from_route_pattern_id !== null && (int) $relationship->from_route_pattern_id !== (int) $firstPattern->getKey())
                                    || ($relationship->to_route_pattern_id !== null && (int) $relationship->to_route_pattern_id !== (int) $secondPattern->getKey())) {
                                    continue;
                                }
                                $transferDistance = $relationship->distance_meters
                                    ?? $this->distanceBetweenStops($firstAlightingStop, $secondBoardingStop);
                                if ($transferDistance > $maxTransferRadiusMeters) {
                                    continue;
                                }
                            }

                            foreach ($secondPattern->patternStops as $secondAlightingPatternStop) {
                                if (
                                    ! Trust::isFactRoutable($secondAlightingPatternStop, ['service', 'sequence', 'alighting'])
                                    || ! $secondAlightingPatternStop->alighting_allowed
                                    || $secondAlightingPatternStop->sequence <= $secondBoardingPatternStop->sequence
                                ) {
                                    continue;
                                }
                                $secondAlightingStop = $alightingById->get($secondAlightingPatternStop->stop_id);
                                if (! $secondAlightingStop instanceof Stop || ! Trust::isRoutable($secondAlightingStop)) {
                                    continue;
                                }

                                $transferLeg = $this->buildTransferLeg(
                                    $firstAlightingStop,
                                    $secondBoardingStop,
                                    $transferDistance,
                                    $relationship,
                                    $firstAlightingPatternStop,
                                    $secondBoardingPatternStop,
                                );
                                $journeys[] = $this->buildJourney(
                                    id: sprintf(
                                        'transfer-%d-%d-%d-%d-%d',
                                        $firstPattern->getKey(),
                                        $firstBoardingPatternStop->sequence,
                                        $firstAlightingPatternStop->sequence,
                                        $secondPattern->getKey(),
                                        $secondAlightingPatternStop->sequence,
                                    ),
                                    origin: $origin,
                                    destination: $destination,
                                    legs: [
                                        $this->buildWalkLeg(
                                            JourneyLegType::WALK_ACCESS,
                                            $origin,
                                            $boardingStop,
                                            $this->distanceFromPlace($origin, $boardingStop),
                                        ),
                                        $this->buildRideLeg(
                                            $firstPattern,
                                            $firstBoardingPatternStop,
                                            $firstAlightingPatternStop,
                                            $this->cueForStop($firstAlightingStop),
                                        ),
                                        $transferLeg,
                                        $this->buildRideLeg(
                                            $secondPattern,
                                            $secondBoardingPatternStop,
                                            $secondAlightingPatternStop,
                                            $this->cueForPlace($destination),
                                        ),
                                        $this->buildWalkLeg(
                                            JourneyLegType::WALK_EGRESS,
                                            $secondAlightingStop,
                                            $destination,
                                            $this->distanceFromPlace($destination, $secondAlightingStop),
                                        ),
                                    ],
                                    models: [
                                        $origin,
                                        $destination,
                                        $boardingStop,
                                        $firstAlightingStop,
                                        $secondBoardingStop,
                                        $secondAlightingStop,
                                        $firstPattern,
                                        $firstPattern->route,
                                        $secondPattern,
                                        $secondPattern->route,
                                        $firstBoardingPatternStop,
                                        $firstAlightingPatternStop,
                                        $secondBoardingPatternStop,
                                        $secondAlightingPatternStop,
                                        $relationship,
                                    ],
                                );
                                if (count($journeys) >= $this->generatedJourneyLimit()) {
                                    return $journeys;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $journeys;
    }

    /** @return list<Journey> */
    private function findMultiTransferJourneys(
        Place $origin,
        Place $destination,
        Collection $boardingStops,
        Collection $alightingStops,
        float $maxTransferRadiusMeters,
        int $maxTransfers,
    ): array {
        $patterns = $this->candidatePatterns($origin->city_id, [], [], false);
        if ($patterns->isEmpty()) {
            return [];
        }

        $relationships = $this->routingTransferRelationships($origin->city_id);
        $boardingOptions = $this->boardingOptions($patterns);
        $relationshipsByFromStop = $relationships->groupBy(fn (TransferRelationship $relationship): int => (int) $relationship->from_stop_id);
        $boardingStopIds = array_fill_keys($boardingStops->modelKeys(), true);
        $initialPatterns = $patterns->filter(
            fn (RoutePattern $pattern): bool => $pattern->patternStops->contains(
                fn (RoutePatternStop $association): bool => isset($boardingStopIds[(int) $association->stop_id])
                    && Trust::isFactRoutable($association, ['service', 'sequence', 'boarding'])
                    && $association->boarding_allowed,
            ),
        );
        $boardingById = $boardingStops->keyBy(fn (Stop $stop): int => $stop->getKey());
        $alightingById = $alightingStops->keyBy(fn (Stop $stop): int => $stop->getKey());
        $states = [];
        $frontier = [];
        $journeys = [];
        $maximumStates = min(max((int) config('biyahe.routing.max_search_states', 5000), 100), 20000);

        foreach ($initialPatterns as $pattern) {
            foreach ($pattern->patternStops as $boardingPatternStop) {
                if (! Trust::isFactRoutable($boardingPatternStop, ['service', 'sequence', 'boarding']) || ! $boardingPatternStop->boarding_allowed) {
                    continue;
                }

                $boardingStop = $boardingById->get($boardingPatternStop->stop_id);
                if (! $boardingStop instanceof Stop) {
                    continue;
                }

                foreach ($pattern->patternStops as $alightingPatternStop) {
                    if (! $this->usableRidePair($boardingPatternStop, $alightingPatternStop)) {
                        continue;
                    }

                    $alightingStop = $alightingPatternStop->stop;
                    if (! $alightingStop instanceof Stop) {
                        continue;
                    }

                    $state = $this->makeRoutingState(
                        rides: [$this->rideDefinition($pattern, $boardingPatternStop, $alightingPatternStop, $boardingStop, $alightingStop)],
                        transfers: [],
                        walkingDistance: $this->distanceFromPlace($origin, $boardingStop),
                        rideStopCount: $alightingPatternStop->sequence - $boardingPatternStop->sequence,
                    );
                    if ($this->enqueueRoutingState($state, $frontier)) {
                        $states[] = $state;
                    }
                    if (count($states) >= $maximumStates) {
                        break 3;
                    }
                }
            }
        }

        $stateIndex = 0;
        while (isset($states[$stateIndex]) && $stateIndex < $maximumStates) {
            $state = $states[$stateIndex++];
            $lastRide = $state['rides'][array_key_last($state['rides'])];

            if ($state['transfer_count'] >= 2) {
                $destinationStop = $alightingById->get($lastRide['alighting_stop']->getKey());
                if ($destinationStop instanceof Stop) {
                    $journeys[] = $this->buildJourneyFromRoutingState($state, $origin, $destination, $destinationStop);
                    if (count($journeys) >= $this->generatedJourneyLimit()) {
                        break;
                    }
                }
            }

            if ($state['transfer_count'] >= $maxTransfers) {
                continue;
            }

            $nextBoardingCandidates = [];
            $currentStopId = (int) $lastRide['alighting_stop']->getKey();
            foreach ($boardingOptions[$currentStopId] ?? [] as $candidate) {
                $nextPattern = $candidate['pattern'];
                if (
                    in_array((int) $nextPattern->route_id, $state['used_route_ids'], true)
                    || in_array((int) $nextPattern->getKey(), $state['used_pattern_ids'], true)
                ) {
                    continue;
                }

                $transfer = $this->transferConnection(
                    $lastRide['alighting_stop'],
                    $lastRide['pattern'],
                    $candidate['stop'],
                    $nextPattern,
                    $relationships,
                    $maxTransferRadiusMeters,
                    $lastRide['alighting'],
                    $candidate['association'],
                );
                if ($transfer !== null) {
                    $nextBoardingCandidates[] = [
                        'pattern' => $nextPattern,
                        'association' => $candidate['association'],
                        'stop' => $candidate['stop'],
                        'transfer' => $transfer,
                    ];
                }
            }
            foreach ($relationshipsByFromStop->get($currentStopId, collect()) as $relationship) {
                $toStopId = (int) $relationship->to_stop_id;
                foreach ($boardingOptions[$toStopId] ?? [] as $candidate) {
                    $nextPattern = $candidate['pattern'];
                    if (
                        in_array((int) $nextPattern->route_id, $state['used_route_ids'], true)
                        || in_array((int) $nextPattern->getKey(), $state['used_pattern_ids'], true)
                    ) {
                        continue;
                    }

                    $transfer = $this->transferConnection(
                        $lastRide['alighting_stop'],
                        $lastRide['pattern'],
                        $candidate['stop'],
                        $nextPattern,
                        $relationships,
                        $maxTransferRadiusMeters,
                        $lastRide['alighting'],
                        $candidate['association'],
                    );
                    if ($transfer !== null) {
                        $nextBoardingCandidates[] = [
                            'pattern' => $nextPattern,
                            'association' => $candidate['association'],
                            'stop' => $candidate['stop'],
                            'transfer' => $transfer,
                        ];
                    }
                }
            }

            foreach ($nextBoardingCandidates as $candidate) {
                $nextPattern = $candidate['pattern'];
                $nextBoardingPatternStop = $candidate['association'];
                $nextBoardingStop = $candidate['stop'];
                foreach ($nextPattern->patternStops as $nextAlightingPatternStop) {
                    if (! $this->usableRidePair($nextBoardingPatternStop, $nextAlightingPatternStop)) {
                        continue;
                    }

                    $nextAlightingStop = $nextAlightingPatternStop->stop;
                    if (! $nextAlightingStop instanceof Stop) {
                        continue;
                    }

                    $nextState = $this->makeRoutingState(
                        rides: array_merge($state['rides'], [$this->rideDefinition(
                            $nextPattern,
                            $nextBoardingPatternStop,
                            $nextAlightingPatternStop,
                            $nextBoardingStop,
                            $nextAlightingStop,
                        )]),
                        transfers: array_merge($state['transfers'], [$candidate['transfer']]),
                        walkingDistance: $state['walking_distance'] + $candidate['transfer']['distance'],
                        rideStopCount: $state['ride_stop_count'] + $nextAlightingPatternStop->sequence - $nextBoardingPatternStop->sequence,
                    );
                    if ($this->enqueueRoutingState($nextState, $frontier)) {
                        $states[] = $nextState;
                    }
                    if (count($states) >= $maximumStates) {
                        break 2;
                    }
                }
            }
            if (count($states) >= $maximumStates) {
                break;
            }
        }

        return $journeys;
    }

    /** @return array<string, mixed> */
    private function makeRoutingState(
        array $rides,
        array $transfers,
        float $walkingDistance,
        int $rideStopCount,
    ): array {
        $routeIds = array_values(array_unique(array_map(
            static fn (array $ride): int => (int) $ride['pattern']->route_id,
            $rides,
        )));
        $patternIds = array_values(array_unique(array_map(
            static fn (array $ride): int => (int) $ride['pattern']->getKey(),
            $rides,
        )));
        $state = [
            'rides' => $rides,
            'transfers' => $transfers,
            'transfer_count' => count($transfers),
            'walking_distance' => $walkingDistance,
            'ride_stop_count' => $rideStopCount,
            'used_route_ids' => $routeIds,
            'used_pattern_ids' => $patternIds,
        ];
        $state['trust_state'] = $this->trustState(
            $this->routingStateModels($state),
            $this->routingStateEvidenceStates($state),
        );

        return $state;
    }

    /** @return array<string, mixed> */
    private function rideDefinition(
        RoutePattern $pattern,
        RoutePatternStop $boarding,
        RoutePatternStop $alighting,
        Stop $boardingStop,
        Stop $alightingStop,
    ): array {
        return [
            'pattern' => $pattern,
            'boarding' => $boarding,
            'alighting' => $alighting,
            'boarding_stop' => $boardingStop,
            'alighting_stop' => $alightingStop,
        ];
    }

    /** @param array<string, mixed> $state @return list<Model|null> */
    private function routingStateModels(array $state): array
    {
        $models = [];
        foreach ($state['rides'] as $ride) {
            $models = array_merge($models, [
                $ride['pattern'],
                $ride['pattern']->route,
                $ride['boarding'],
                $ride['alighting'],
                $ride['boarding_stop'],
                $ride['alighting_stop'],
            ]);
        }
        foreach ($state['transfers'] as $transfer) {
            $models = array_merge($models, [
                $transfer['relationship'],
                $transfer['from_association'],
                $transfer['to_association'],
                $transfer['from_stop'],
                $transfer['to_stop'],
            ]);
        }

        return $models;
    }

    /** @param array<string, mixed> $state @return list<string> */
    private function routingStateEvidenceStates(array $state): array
    {
        $states = [];
        foreach ($state['rides'] as $ride) {
            $states = array_merge($states, $this->rideEvidenceStates($ride['boarding'], $ride['alighting']));
        }

        return $states;
    }

    private function usableRidePair(RoutePatternStop $boarding, RoutePatternStop $alighting): bool
    {
        return Trust::isFactRoutable($boarding, ['service', 'sequence', 'boarding'])
            && Trust::isRoutable($boarding->stop)
            && Trust::isFactRoutable($alighting, ['service', 'sequence', 'alighting'])
            && Trust::isRoutable($alighting->stop)
            && $alighting->alighting_allowed
            && $alighting->sequence > $boarding->sequence;
    }

    /** @return Collection<int, TransferRelationship> */
    private function routingTransferRelationships(int $cityId): Collection
    {
        return TransferRelationship::query()
            ->with(['provenanceSource', 'fromStop.provenanceSource', 'fromStop.boardingProvenanceSource', 'toStop.provenanceSource', 'toStop.boardingProvenanceSource'])
            ->where('city_id', $cityId)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [
                VerificationState::VERIFIED->value,
                VerificationState::UNVERIFIED->value,
            ])
            ->whereIn('review_status', ['SUPPORTED', 'PENDING_REVIEW'])
            ->orderBy('id')
            ->limit((int) config('biyahe.routing.max_transfer_relationships', 200))
            ->get()
            ->keyBy(fn (TransferRelationship $relationship): string => $relationship->from_stop_id.':'.$relationship->to_stop_id);
    }

    /** @return array<int, list<array{pattern: RoutePattern, association: RoutePatternStop, stop: Stop}>> */
    private function boardingOptions(Collection $patterns): array
    {
        $options = [];
        foreach ($patterns as $pattern) {
            foreach ($pattern->patternStops as $association) {
                if (! Trust::isFactRoutable($association, ['service', 'sequence', 'boarding']) || ! $association->boarding_allowed) {
                    continue;
                }
                $stop = $association->stop;
                if (! $stop instanceof Stop || ! Trust::isRoutable($stop)) {
                    continue;
                }
                $options[(int) $association->stop_id][] = [
                    'pattern' => $pattern,
                    'association' => $association,
                    'stop' => $stop,
                ];
            }
        }

        return $options;
    }

    /** @return array<string, mixed>|null */
    private function transferConnection(
        Stop $fromStop,
        RoutePattern $fromPattern,
        Stop $toStop,
        RoutePattern $toPattern,
        Collection $relationships,
        float $maxTransferRadiusMeters,
        RoutePatternStop $fromAssociation,
        RoutePatternStop $toAssociation,
    ): ?array {
        if (! Trust::isRoutable($fromStop) || ! Trust::isRoutable($toStop)) {
            return null;
        }

        if ($fromStop->getKey() === $toStop->getKey()) {
            return [
                'from_stop' => $fromStop,
                'to_stop' => $toStop,
                'distance' => 0.0,
                'relationship' => null,
                'from_association' => $fromAssociation,
                'to_association' => $toAssociation,
            ];
        }

        $relationship = $relationships->get($fromStop->getKey().':'.$toStop->getKey());
        if (! $relationship instanceof TransferRelationship) {
            return null;
        }
        if (
            ($relationship->from_route_pattern_id !== null && (int) $relationship->from_route_pattern_id !== (int) $fromPattern->getKey())
            || ($relationship->to_route_pattern_id !== null && (int) $relationship->to_route_pattern_id !== (int) $toPattern->getKey())
        ) {
            return null;
        }

        $distance = $relationship->distance_meters ?? $this->distanceBetweenStops($fromStop, $toStop);
        if (! is_finite($distance) || $distance > $maxTransferRadiusMeters) {
            return null;
        }

        return [
            'from_stop' => $fromStop,
            'to_stop' => $toStop,
            'distance' => $distance,
            'relationship' => $relationship,
            'from_association' => $fromAssociation,
            'to_association' => $toAssociation,
        ];
    }

    /** @param array<string, mixed> $state */
    private function enqueueRoutingState(array $state, array &$frontier): bool
    {
        $lastRide = $state['rides'][array_key_last($state['rides'])];
        $routeIds = $state['used_route_ids'];
        sort($routeIds);
        $key = implode(':', [
            $lastRide['pattern']->getKey(),
            $lastRide['alighting_stop']->getKey(),
            implode(',', $routeIds),
        ]);
        $existing = $frontier[$key] ?? [];
        foreach ($existing as $candidate) {
            if ($this->routingStateDominates($candidate, $state)) {
                return false;
            }
        }

        $frontier[$key] = array_values(array_filter(
            $existing,
            fn (array $candidate): bool => ! $this->routingStateDominates($state, $candidate),
        ));
        $frontier[$key][] = $state;
        $frontier[$key] = array_slice($frontier[$key], 0, (int) config('biyahe.routing.max_frontier_states_per_key', 8));

        return in_array($state, $frontier[$key], true);
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function routingStateDominates(array $left, array $right): bool
    {
        return $left['transfer_count'] <= $right['transfer_count']
            && $left['walking_distance'] <= $right['walking_distance']
            && $left['ride_stop_count'] <= $right['ride_stop_count']
            && $left['trust_state']->rank() <= $right['trust_state']->rank();
    }

    private function buildJourneyFromRoutingState(
        array $state,
        Place $origin,
        Place $destination,
        Stop $destinationStop,
    ): Journey {
        $firstRide = $state['rides'][0];
        $legs = [
            $this->buildWalkLeg(
                JourneyLegType::WALK_ACCESS,
                $origin,
                $firstRide['boarding_stop'],
                $this->distanceFromPlace($origin, $firstRide['boarding_stop']),
            ),
        ];
        $models = [$origin, $destination];

        foreach ($state['rides'] as $index => $ride) {
            $isLastRide = $index === count($state['rides']) - 1;
            $legs[] = $this->buildRideLeg(
                $ride['pattern'],
                $ride['boarding'],
                $ride['alighting'],
                $isLastRide ? $this->cueForPlace($destination) : $this->cueForStop($ride['alighting_stop']),
            );
            $models = array_merge($models, [
                $ride['boarding_stop'],
                $ride['alighting_stop'],
                $ride['pattern'],
                $ride['pattern']->route,
                $ride['boarding'],
                $ride['alighting'],
            ]);

            if (! $isLastRide) {
                $transfer = $state['transfers'][$index];
                $legs[] = $this->buildTransferLeg(
                    $transfer['from_stop'],
                    $transfer['to_stop'],
                    $transfer['distance'],
                    $transfer['relationship'],
                    $transfer['from_association'],
                    $transfer['to_association'],
                );
                $models = array_merge($models, [
                    $transfer['relationship'],
                    $transfer['from_association'],
                    $transfer['to_association'],
                ]);
            }
        }

        $lastRide = $state['rides'][array_key_last($state['rides'])];
        $legs[] = $this->buildWalkLeg(
            JourneyLegType::WALK_EGRESS,
            $lastRide['alighting_stop'],
            $destination,
            $this->distanceFromPlace($destination, $destinationStop),
        );
        $signature = collect($state['rides'])
            ->map(fn (array $ride): string => implode(':', [
                $ride['pattern']->getKey(),
                $ride['boarding_stop']->getKey(),
                $ride['alighting_stop']->getKey(),
            ]))
            ->implode('|');

        return $this->buildJourney(
            id: 'multi-'.substr(hash('sha256', $signature), 0, 24),
            origin: $origin,
            destination: $destination,
            legs: $legs,
            models: $models,
        );
    }

    private function buildTransferLeg(
        Stop $fromStop,
        Stop $toStop,
        float $distanceMeters,
        ?TransferRelationship $relationship,
        ?RoutePatternStop $fromAssociation = null,
        ?RoutePatternStop $toAssociation = null,
    ): JourneyLeg {
        $models = [$fromStop, $toStop, $relationship, $fromAssociation, $toAssociation];
        $trustState = $this->trustState($models, [Trust::effectiveFactState($toStop, 'boarding')]);
        $instruction = $distanceMeters <= 0.01
            ? 'Transfer at '.$fromStop->name.'. No separate walking distance is mapped; confirm the waiting side locally.'
            : 'Walk between stops; this is a straight-line distance estimate, not a pedestrian route.';
        if ($relationship?->landmark !== null && trim($relationship->landmark) !== '') {
            $instruction = 'Transfer near '.$relationship->landmark.'. '.$instruction;
        }
        if ($relationship?->practical_note !== null && trim($relationship->practical_note) !== '') {
            $instruction .= ' '.$relationship->practical_note;
        }

        return new JourneyLeg(
            type: JourneyLegType::TRANSFER_WALK,
            origin: $this->stopSummary($fromStop),
            destination: $this->stopSummary($toStop),
            distanceMeters: $distanceMeters,
            trust: $trustState,
            provenance: $this->provenanceFor($models),
            evidence: [
                'relationship' => $relationship === null ? [
                    'type' => 'SHARED_STOP',
                    'state' => JourneyTrustState::VERIFIED->value,
                ] : [
                    'type' => 'TRANSFER',
                    'id' => $relationship->getKey(),
                    'stable_id' => $relationship->stable_id,
                    'verification_state' => Trust::rawState($relationship),
                    'review_status' => $relationship->review_status?->value ?? $relationship->review_status,
                    'provenance_source_id' => $relationship->provenance_source_id,
                    'freshness' => (new EvidenceFreshnessPolicy)->classify(
                        $relationship->relationLoaded('provenanceSource') && $relationship->provenanceSource instanceof ProvenanceSource
                            ? $relationship->provenanceSource
                            : null,
                        'TRANSFER',
                    )->value,
                ],
                'from_association' => $fromAssociation === null ? null : $this->patternStopEvidence($fromAssociation),
                'to_association' => $toAssociation === null ? null : $this->patternStopEvidence($toAssociation),
            ],
            instruction: $instruction,
        );
    }

    private function buildWalkLeg(
        JourneyLegType $type,
        Place|Stop $origin,
        Place|Stop $destination,
        float $distanceMeters,
    ): JourneyLeg {
        $from = $origin instanceof Place ? $this->placeSummary($origin) : $this->stopSummary($origin);
        $to = $destination instanceof Place ? $this->placeSummary($destination) : $this->stopSummary($destination);

        return new JourneyLeg(
            type: $type,
            origin: $from,
            destination: $to,
            distanceMeters: $distanceMeters,
            instruction: 'Walking distance estimate only; no pedestrian route is shown.',
        );
    }

    private function buildRideLeg(
        RoutePattern $pattern,
        RoutePatternStop $boardingPatternStop,
        RoutePatternStop $alightingPatternStop,
        ?string $driverCue,
    ): JourneyLeg {
        $route = $pattern->route;
        $models = [
            $pattern,
            $route,
            $boardingPatternStop,
            $alightingPatternStop,
            $boardingPatternStop->stop,
            $alightingPatternStop->stop,
        ];
        $trustState = $this->trustState(
            [$pattern, $route, $boardingPatternStop->stop, $alightingPatternStop->stop],
            $this->rideEvidenceStates($boardingPatternStop, $alightingPatternStop),
        );
        $driverInstruction = $driverCue === null || trim($driverCue) === ''
            ? null
            : 'Tell the driver: "'.$driverCue.'".';
        $boardingInstruction = $this->boardingInstruction($boardingPatternStop->stop);
        $getOffGuidance = 'Get off near '.$alightingPatternStop->stop->name.'.';
        if ($trustState !== JourneyTrustState::VERIFIED) {
            $getOffGuidance .= ' The mapped stop association is still being checked.';
        }

        return new JourneyLeg(
            type: JourneyLegType::RIDE,
            origin: $this->stopSummary($boardingPatternStop->stop),
            destination: $this->stopSummary($alightingPatternStop->stop),
            route: $this->routeSummary($route),
            pattern: $this->patternSummary($pattern),
            boardingStop: $this->stopSummary($boardingPatternStop->stop),
            alightingStop: $this->stopSummary($alightingPatternStop->stop),
            stopCount: $alightingPatternStop->sequence - $boardingPatternStop->sequence,
            boardingSequence: $boardingPatternStop->sequence,
            alightingSequence: $alightingPatternStop->sequence,
            boardingInstruction: $boardingInstruction,
            boardingAssociation: Trust::status($boardingPatternStop),
            alightingAssociation: Trust::status($alightingPatternStop),
            driverInstruction: $driverInstruction,
            getOffGuidance: $getOffGuidance,
            trust: $trustState,
            provenance: $this->provenanceFor($models),
            evidence: [
                'route' => $this->modelEvidence($route, 'ROUTE_IDENTITY'),
                'pattern' => $this->modelEvidence($pattern, 'ROUTE_PATTERN'),
                'boarding' => [
                    'association' => $this->patternStopEvidence($boardingPatternStop),
                    'stop' => $this->stopEvidence($boardingPatternStop->stop),
                ],
                'alighting' => [
                    'association' => $this->patternStopEvidence($alightingPatternStop),
                    'stop' => $this->stopEvidence($alightingPatternStop->stop),
                ],
            ],
            geometry: $this->patternGeometry($pattern),
            instruction: 'Board the displayed route in the displayed direction.',
        );
    }

    /** @param list<JourneyLeg> $legs @param list<\Illuminate\Database\Eloquent\Model|null> $models */
    private function buildJourney(string $id, Place $origin, Place $destination, array $legs, array $models): Journey
    {
        $legTrust = array_values(array_filter(array_map(
            static fn (JourneyLeg $leg): ?string => in_array($leg->type, [JourneyLegType::RIDE, JourneyLegType::TRANSFER_WALK], true)
                ? $leg->trust?->value
                : null,
            $legs,
        )));
        $trustState = $this->trustState($models, $legTrust);
        $walkingDistance = array_sum(array_map(
            static fn (JourneyLeg $leg): float => $leg->distanceMeters ?? 0,
            array_filter($legs, static fn (JourneyLeg $leg): bool => $leg->type !== JourneyLegType::RIDE),
        ));
        $rideStopCount = array_sum(array_map(
            static fn (JourneyLeg $leg): int => $leg->stopCount ?? 0,
            array_filter($legs, static fn (JourneyLeg $leg): bool => $leg->type === JourneyLegType::RIDE),
        ));

        return new Journey(
            id: $id,
            origin: $this->placeSummary($origin),
            destination: $this->placeSummary($destination),
            legs: $legs,
            transferCount: count(array_filter(
                $legs,
                static fn (JourneyLeg $leg): bool => $leg->type === JourneyLegType::TRANSFER_WALK,
            )),
            walkingDistanceMeters: $walkingDistance,
            rideStopCount: $rideStopCount,
            trustState: $trustState,
            provenance: $this->provenanceFor($models),
            warnings: $this->warningsFor($trustState),
        );
    }

    /** @param list<Model|null> $models */
    private function trustState(array $models, array $additionalStates = []): JourneyTrustState
    {
        $states = collect($models)
            ->filter(fn ($model): bool => $model instanceof Model)
            ->reject(fn (Model $model): bool => $model->getTable() === 'route_pattern_stops')
            ->map(fn (Model $model): ?string => Trust::effectiveState($model))
            ->filter()
            ->merge($additionalStates)
            ->values();

        if ($states->isNotEmpty() && $states->every(fn (string $state): bool => $state === VerificationState::VERIFIED->value)) {
            return JourneyTrustState::VERIFIED;
        }

        if ($states->contains(VerificationState::VERIFIED->value)) {
            return JourneyTrustState::MIXED;
        }

        return JourneyTrustState::UNVERIFIED;
    }

    /** @return list<string> */
    private function warningsFor(JourneyTrustState $trustState): array
    {
        return match ($trustState) {
            JourneyTrustState::VERIFIED => [],
            JourneyTrustState::MIXED => ['Some route details are still being checked.'],
            JourneyTrustState::UNVERIFIED => ['Route information is not yet fully verified.'],
        };
    }

    /** @param list<Model|null> $models @return list<array<string, mixed>> */
    private function provenanceFor(array $models): array
    {
        $sources = [];
        foreach ($models as $model) {
            if (! $model instanceof Model) {
                continue;
            }
            foreach (Trust::provenanceSources($model) as $source) {
                if (! $source instanceof ProvenanceSource) {
                    continue;
                }
                $key = $source->stable_id ?? (string) $source->getKey();
                $sources[$key] = $this->sourceSummary($source);
            }
        }

        return array_values($sources);
    }

    /** @return array<string, mixed> */
    private function sourceSummary(ProvenanceSource $source): array
    {
        return [
            'stable_id' => $source->stable_id,
            'type' => $source->source_type,
            'title' => $source->title,
            'publisher' => $source->publisher,
            'url' => $source->url,
            'published_at' => $source->published_at?->toDateString() ?? $source->published_at,
            'observed_at' => $source->observed_at?->toDateString() ?? $source->observed_at,
            'checked_at' => $source->checked_at?->toDateString() ?? $source->checked_at,
            'effective_from' => $source->effective_from?->toDateString() ?? $source->effective_from,
            'effective_until' => $source->effective_until?->toDateString() ?? $source->effective_until,
            'evidence_scope' => $source->evidence_scope,
            'conflict_status' => $source->conflict_status?->value ?? $source->conflict_status,
            'conflict_group' => $source->conflict_group,
            'superseded_by_id' => $source->superseded_by_id,
            'license' => $source->license,
            'verified_by' => $source->verified_by,
        ];
    }

    /** @return array<string, mixed> */
    private function placeSummary(Place $place): array
    {
        return [
            'type' => 'PLACE',
            'id' => $place->getKey(),
            'stable_id' => $place->stable_id,
            'name' => $place->name,
            'slug' => $place->slug,
            'location' => $place->location_point,
            'location_freshness' => (new EvidenceFreshnessPolicy)->classify(
                $place->relationLoaded('provenanceSource') && $place->provenanceSource instanceof ProvenanceSource
                    ? $place->provenanceSource
                    : null,
                'PLACE_LOCATION',
            )->value,
            'verification' => Trust::status($place),
        ];
    }

    /** @return array<string, mixed> */
    private function stopSummary(Stop $stop): array
    {
        return [
            'type' => 'STOP',
            'id' => $stop->getKey(),
            'name' => $stop->name,
            'code' => $stop->code,
            'location' => $stop->location_point,
            'is_boarding_point' => (bool) $stop->is_boarding_point,
            'boarding_notes' => $stop->boarding_notes,
            'boarding_side' => $stop->boarding_side,
            'direction_note' => $stop->direction_note,
            'boarding_precision' => $stop->boarding_precision?->value ?? $stop->boarding_precision,
            'boarding_verification' => $stop->boarding_verification_state?->value ?? $stop->boarding_verification_state,
            'nearby_place' => $stop->relationLoaded('nearbyPlace') && $stop->nearbyPlace !== null
                ? ['id' => $stop->nearbyPlace->getKey(), 'name' => $stop->nearbyPlace->name]
                : null,
            'verification' => Trust::status($stop),
        ];
    }

    /** @return array<string, mixed> */
    private function routeSummary(?Route $route): ?array
    {
        if ($route === null) {
            return null;
        }

        return [
            'id' => $route->getKey(),
            'code' => $route->code,
            'name' => $route->name,
            'vehicle_type' => $route->relationLoaded('vehicleType') && $route->vehicleType !== null
                ? ['code' => $route->vehicleType->code, 'name' => $route->vehicleType->name]
                : null,
            'verification' => Trust::status($route),
        ];
    }

    /** @return array<string, mixed> */
    private function patternSummary(RoutePattern $pattern): array
    {
        return [
            'id' => $pattern->getKey(),
            'name' => $pattern->name,
            'direction' => $pattern->direction,
            'verification' => Trust::status($pattern),
        ];
    }

    /** @return array<string, mixed>|null */
    private function patternGeometry(RoutePattern $pattern): ?array
    {
        return Geo::geometryGeoJson($pattern->geometry_wkt ?? $pattern->getRawOriginal('geometry'));
    }

    private function cueForPlace(Place $place): string
    {
        if ($place->relationLoaded('aliases')) {
            $alias = $place->aliases->first(fn ($placeAlias): bool => (bool) $placeAlias->is_active);
            if ($alias !== null && trim((string) $alias->alias) !== '') {
                return $alias->alias;
            }
        }

        return $place->name;
    }

    private function distanceFromPlace(Place $place, Stop $stop): float
    {
        if (isset($stop->distance_meters) && is_numeric($stop->distance_meters)) {
            return (float) $stop->distance_meters;
        }

        $placeLocation = $place->location_point;
        $stopLocation = $stop->location_point;
        if ($placeLocation === null || $stopLocation === null) {
            return 0;
        }

        return Geo::distanceMeters(
            $placeLocation['lat'],
            $placeLocation['lng'],
            $stopLocation['lat'],
            $stopLocation['lng'],
        );
    }

    private function distanceBetweenStops(Stop $fromStop, Stop $toStop): float
    {
        $fromLocation = $fromStop->location_point;
        $toLocation = $toStop->location_point;
        if ($fromLocation === null || $toLocation === null) {
            return INF;
        }

        return Geo::distanceMeters(
            $fromLocation['lat'],
            $fromLocation['lng'],
            $toLocation['lat'],
            $toLocation['lng'],
        );
    }

    /** @return Collection<int, RoutePattern> */
    private function candidatePatterns(int $cityId, array $boardingStopIds = [], array $alightingStopIds = [], bool $requireBoarding = true): Collection
    {
        $query = $this->patternQuery($cityId);
        if ($requireBoarding && $boardingStopIds !== []) {
            $query->whereHas('patternStops', function (Builder $builder) use ($boardingStopIds): void {
                $builder
                    ->whereIn('route_pattern_stops.stop_id', $boardingStopIds)
                    ->where('route_pattern_stops.boarding_allowed', true);
            });
        }
        if ($alightingStopIds !== []) {
            $query->whereHas('patternStops', function (Builder $builder) use ($alightingStopIds): void {
                $builder
                    ->whereIn('route_pattern_stops.stop_id', $alightingStopIds)
                    ->where('route_pattern_stops.alighting_allowed', true);
            });
        }

        return $query
            ->orderBy('route_patterns.route_id')
            ->orderBy('route_patterns.id')
            ->limit((int) config('biyahe.routing.max_pattern_candidates', 50))
            ->get();
    }

    private function hasExcludedRoutingData(int $cityId, array $boardingStopIds, array $alightingStopIds): bool
    {
        if ($boardingStopIds === [] || $alightingStopIds === []) {
            return false;
        }

        $routableStates = [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value];

        return RoutePattern::query()
            ->where('route_patterns.is_active', true)
            ->where('route_patterns.is_demo', false)
            ->whereHas('route', function (Builder $builder) use ($cityId): void {
                $builder->where('routes.city_id', $cityId);
            })
            ->whereHas('patternStops', function (Builder $builder) use ($boardingStopIds): void {
                $builder
                    ->whereIn('route_pattern_stops.stop_id', $boardingStopIds)
                    ->where('route_pattern_stops.boarding_allowed', true);
            })
            ->whereHas('patternStops', function (Builder $builder) use ($alightingStopIds): void {
                $builder
                    ->whereIn('route_pattern_stops.stop_id', $alightingStopIds)
                    ->where('route_pattern_stops.alighting_allowed', true);
            })
            ->where(function (Builder $builder) use ($routableStates): void {
                $builder
                    ->whereNotIn('route_patterns.verification_state', $routableStates)
                    ->orWhereHas('route', function (Builder $route): void {
                        $route
                            ->where('routes.is_active', false)
                            ->orWhere('routes.is_demo', true)
                            ->orWhereNotIn('routes.verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value]);
                    });
            })
            ->exists();
    }

    /** @return Builder<RoutePattern> */
    private function patternQuery(int $cityId): Builder
    {
        $query = RoutePattern::query()
            ->with([
                'provenanceSource',
                'route.provenanceSource',
                'route.vehicleType',
                'patternStops' => function ($builder): void {
                    $builder->orderBy('sequence')->with([
                        'provenanceSource',
                        'serviceProvenanceSource',
                        'sequenceProvenanceSource',
                        'boardingProvenanceSource',
                        'alightingProvenanceSource',
                        'stop' => function ($stop): void {
                            $stop->with(['provenanceSource', 'boardingProvenanceSource', 'nearbyPlace']);
                            if (config('database.default') === 'pgsql') {
                                $stop->addSelect(['stops.*', DB::raw('ST_AsText(stops.location) AS location_wkt')]);
                            }
                        },
                    ]);
                },
            ]);
        Trust::applyRoutable($query, 'route_patterns');
        $query->whereHas('route', function (Builder $builder) use ($cityId): void {
            Trust::applyRoutable($builder, 'routes')->where('routes.city_id', $cityId);
        });

        if (config('database.default') === 'pgsql') {
            $query->addSelect(['route_patterns.*', DB::raw('ST_AsText(route_patterns.geometry) AS geometry_wkt')]);
        }

        return $query;
    }

    private function cueForStop(Stop $stop): ?string
    {
        if ($stop->relationLoaded('nearbyPlace') && $stop->nearbyPlace !== null) {
            return $stop->nearbyPlace->name;
        }

        return preg_match('/^OSM platform /', $stop->name) === 1 ? null : $stop->name;
    }

    private function boardingInstruction(Stop $stop): string
    {
        $location = $stop->relationLoaded('nearbyPlace') && $stop->nearbyPlace !== null
            ? $stop->name.' near '.$stop->nearbyPlace->name
            : $stop->name;
        $details = array_values(array_filter([
            $stop->boarding_notes,
            $stop->boarding_side === null ? null : 'Wait on '.$stop->boarding_side.'.',
            $stop->direction_note,
        ], static fn (?string $detail): bool => $detail !== null && trim($detail) !== ''));

        $prefix = match ($stop->boarding_precision?->value ?? $stop->boarding_precision) {
            'EXACT_BOARDING_POINT' => 'Wait at '.$location.'.',
            'BOARDING_AREA' => 'Wait in the mapped boarding area near '.$location.'.',
            'APPROXIMATE_LOCATION' => 'Wait near '.$location.'.',
            default => 'Wait near '.$location.'. Exact boarding is not confirmed.',
        };

        if ($details === []) {
            return $prefix;
        }

        return $prefix.' '.implode(' ', $details);
    }

    /** @return array<string, mixed> */
    private function modelEvidence(Model $model, string $claim): array
    {
        $source = $model->relationLoaded('provenanceSource')
            && $model->provenanceSource instanceof ProvenanceSource
            ? $model->provenanceSource
            : null;

        return [
            'claim' => $claim,
            'id' => $model->getKey(),
            'stable_id' => $model->stable_id,
            'state' => Trust::effectiveState($model),
            'verification_state' => Trust::rawState($model),
            'provenance_source_id' => $model->provenance_source_id,
            'freshness' => (new EvidenceFreshnessPolicy)->classify($source, $claim)->value,
        ];
    }

    /** @return array<string, mixed> */
    private function patternStopEvidence(RoutePatternStop $association): array
    {
        return [
            'id' => $association->getKey(),
            'stable_id' => $association->stable_id,
            'state' => Trust::effectiveState($association),
            'verification_state' => Trust::rawState($association),
            'service' => $this->factEvidence($association, 'service'),
            'sequence' => $this->factEvidence($association, 'sequence'),
            'boarding' => $this->factEvidence($association, 'boarding'),
            'alighting' => $this->factEvidence($association, 'alighting'),
            'boarding_allowed' => (bool) $association->boarding_allowed,
            'alighting_allowed' => (bool) $association->alighting_allowed,
        ];
    }

    /** @return array<string, mixed> */
    private function factEvidence(RoutePatternStop $association, string $fact): array
    {
        $sourceId = $association->{$fact.'_provenance_source_id'} ?? null;
        $relation = $fact.'ProvenanceSource';
        $source = $association->relationLoaded($relation) && $association->getRelation($relation) instanceof ProvenanceSource
            ? $association->getRelation($relation)
            : null;

        return [
            'state' => Trust::effectiveFactState($association, $fact),
            'provenance_source_id' => $sourceId,
            'freshness' => (new EvidenceFreshnessPolicy)->classify($source, 'PATTERN_STOP_'.strtoupper($fact))->value,
        ];
    }

    /** @return array<string, mixed> */
    private function stopEvidence(Stop $stop): array
    {
        $source = $stop->relationLoaded('boardingProvenanceSource') && $stop->boardingProvenanceSource instanceof ProvenanceSource
            ? $stop->boardingProvenanceSource
            : null;

        return [
            'id' => $stop->getKey(),
            'stable_id' => $stop->stable_id,
            'state' => Trust::effectiveState($stop),
            'verification_state' => Trust::rawState($stop),
            'boarding_precision' => $stop->boarding_precision?->value ?? $stop->boarding_precision,
            'boarding_verification_state' => Trust::effectiveFactState($stop, 'boarding'),
            'provenance_source_id' => $stop->boarding_provenance_source_id,
            'freshness' => (new EvidenceFreshnessPolicy)->classify($source, 'BOARDING')->value,
            'location_freshness' => (new EvidenceFreshnessPolicy)->classify(
                $stop->relationLoaded('provenanceSource') && $stop->provenanceSource instanceof ProvenanceSource
                    ? $stop->provenanceSource
                    : null,
                'STOP_LOCATION',
            )->value,
        ];
    }

    /** @return list<string> */
    private function rideEvidenceStates(RoutePatternStop $boarding, RoutePatternStop $alighting): array
    {
        return [
            Trust::effectiveFactSetState($boarding, ['service', 'sequence', 'boarding']),
            Trust::effectiveFactSetState($alighting, ['service', 'sequence', 'alighting']),
            Trust::effectiveFactState($boarding->stop, 'boarding'),
        ];
    }

    private function generatedJourneyLimit(): int
    {
        return min(max((int) config('biyahe.routing.max_generated_journeys', 300), 10), 1000);
    }

    /** @param list<Journey> $journeys @return list<Journey> */
    private function deduplicate(array $journeys): array
    {
        $unique = [];
        foreach ($journeys as $journey) {
            $signature = collect($journey->legs)
                ->filter(fn (JourneyLeg $leg): bool => in_array($leg->type, [JourneyLegType::RIDE, JourneyLegType::TRANSFER_WALK], true))
                ->map(function (JourneyLeg $leg): string {
                    return implode(':', [
                        $leg->type->value,
                        $leg->pattern['id'] ?? 'none',
                        $leg->boardingStop['id'] ?? $leg->origin['id'] ?? 'none',
                        $leg->alightingStop['id'] ?? $leg->destination['id'] ?? 'none',
                    ]);
                })
                ->implode('|');
            $unique[$signature !== '' ? $signature : $journey->id] ??= $journey;
        }

        return array_values($unique);
    }
}
