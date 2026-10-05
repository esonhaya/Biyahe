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
    ): JourneySearchResult {
        $accessRadiusMeters ??= (float) config('biyahe.routing.max_access_radius_meters', 1000);
        $transferRadiusMeters ??= (float) config('biyahe.routing.max_transfer_radius_meters', 600);

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

        $journeys = array_merge(
            $this->findDirectJourneys($origin, $destination, $boardingStops, $alightingStops),
            $this->findTransferJourneys(
                $origin,
                $destination,
                $boardingStops,
                $alightingStops,
                min($transferRadiusMeters, (float) config('biyahe.routing.max_transfer_radius_meters', 600)),
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
                    : 'Biyahe does not currently know a supported direct or one-transfer journey for these places. Coverage is still incomplete.',
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
                if (! Trust::isRoutable($boardingPatternStop) || ! $boardingPatternStop->boarding_allowed) {
                    continue;
                }

                $boardingStop = $boardingById->get($boardingPatternStop->stop_id);
                if (! $boardingStop instanceof Stop) {
                    continue;
                }

                foreach ($pattern->patternStops as $alightingPatternStop) {
                    if (
                        ! Trust::isRoutable($alightingPatternStop)
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
            ->with(['provenanceSource', 'fromStop.provenanceSource', 'toStop.provenanceSource'])
            ->where('city_id', $origin->city_id)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [
                VerificationState::VERIFIED->value,
                VerificationState::UNVERIFIED->value,
            ])
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
                if (! Trust::isRoutable($firstBoardingPatternStop) || ! $firstBoardingPatternStop->boarding_allowed) {
                    continue;
                }
                $boardingStop = $boardingById->get($firstBoardingPatternStop->stop_id);
                if (! $boardingStop instanceof Stop) {
                    continue;
                }

                foreach ($firstPattern->patternStops as $firstAlightingPatternStop) {
                    if (
                        ! Trust::isRoutable($firstAlightingPatternStop)
                        || ! $firstAlightingPatternStop->alighting_allowed
                        || $firstAlightingPatternStop->sequence <= $firstBoardingPatternStop->sequence
                    ) {
                        continue;
                    }
                    $firstAlightingStop = $firstAlightingPatternStop->stop;
                    if (! $firstAlightingStop instanceof Stop) {
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
                            if (! Trust::isRoutable($secondBoardingPatternStop) || ! $secondBoardingPatternStop->boarding_allowed) {
                                continue;
                            }
                            $secondBoardingStop = $secondBoardingPatternStop->stop;
                            if (! $secondBoardingStop instanceof Stop) {
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
                                    ! Trust::isRoutable($secondAlightingPatternStop)
                                    || ! $secondAlightingPatternStop->alighting_allowed
                                    || $secondAlightingPatternStop->sequence <= $secondBoardingPatternStop->sequence
                                ) {
                                    continue;
                                }
                                $secondAlightingStop = $alightingById->get($secondAlightingPatternStop->stop_id);
                                if (! $secondAlightingStop instanceof Stop) {
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

    private function buildTransferLeg(
        Stop $fromStop,
        Stop $toStop,
        float $distanceMeters,
        ?TransferRelationship $relationship,
        ?RoutePatternStop $fromAssociation = null,
        ?RoutePatternStop $toAssociation = null,
    ): JourneyLeg {
        $models = [$fromStop, $toStop, $relationship, $fromAssociation, $toAssociation];
        $trustState = $this->trustState($models);

        return new JourneyLeg(
            type: JourneyLegType::TRANSFER_WALK,
            origin: $this->stopSummary($fromStop),
            destination: $this->stopSummary($toStop),
            distanceMeters: $distanceMeters,
            trust: $trustState,
            provenance: $this->provenanceFor($models),
            instruction: 'Walk between stops; this is a straight-line distance estimate, not a pedestrian route.',
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
        $trustState = $this->trustState($models);
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
            geometry: $this->patternGeometry($pattern),
            instruction: 'Board the displayed route in the displayed direction.',
        );
    }

    /** @param list<JourneyLeg> $legs @param list<\Illuminate\Database\Eloquent\Model|null> $models */
    private function buildJourney(string $id, Place $origin, Place $destination, array $legs, array $models): Journey
    {
        $trustState = $this->trustState($models);
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
    private function trustState(array $models): JourneyTrustState
    {
        $states = collect($models)
            ->filter(fn ($model): bool => $model instanceof Model)
            ->map(function ($model): ?string {
                $state = $model->verification_state ?? null;

                return $state instanceof VerificationState ? $state->value : $state;
            })
            ->filter()
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
            if (! $model instanceof Model || ! $model->relationLoaded('provenanceSource')) {
                continue;
            }
            $source = $model->getRelation('provenanceSource');
            if (! $source instanceof ProvenanceSource) {
                continue;
            }
            $key = $source->stable_id ?? (string) $source->getKey();
            $sources[$key] = $this->sourceSummary($source);
        }

        return array_values($sources);
    }

    /** @return array<string, mixed> */
    private function sourceSummary(ProvenanceSource $source): array
    {
        return [
            'type' => $source->source_type,
            'title' => $source->title,
            'url' => $source->url,
            'checked_at' => $source->checked_at?->toDateString() ?? $source->checked_at,
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
            'name' => $place->name,
            'slug' => $place->slug,
            'location' => $place->location_point,
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
                        'stop' => function ($stop): void {
                            $stop->with(['provenanceSource', 'nearbyPlace']);
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

        if ($details === []) {
            return 'Wait near '.$location.'. Exact curbside boarding is not confirmed.';
        }

        return 'Wait near '.$location.'. '.implode(' ', $details);
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
            $unique[$journey->id] = $journey;
        }

        return array_values($unique);
    }
}
