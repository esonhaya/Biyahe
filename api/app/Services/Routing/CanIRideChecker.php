<?php

namespace App\Services\Routing;

use App\Enums\JourneyTrustState;
use App\Enums\PedestrianAccessClassification;
use App\Enums\PedestrianRoutePurpose;
use App\Enums\PedestrianRouteStatus;
use App\Enums\VerificationState;
use App\Models\Place;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\Stop;
use App\Support\Geo;
use App\Support\Trust;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CanIRideChecker
{
    /** @var array<string, Route|null> */
    private array $routeCache = [];

    /** @var array<int, Collection<int, RoutePattern>> */
    private array $patternCache = [];

    /** @var array<string, Collection<int, Stop>> */
    private array $nearbyStopCache = [];

    public function __construct(
        private readonly NearbyStopFinder $nearbyStopFinder,
        private readonly PedestrianRouter $pedestrianRouter,
    ) {}

    public function check(
        Place $origin,
        Place $destination,
        ?string $routeCode = null,
        ?int $routeId = null,
        ?int $boardingStopId = null,
    ): CanIRideResult {
        $route = $this->findRoute($origin, $routeCode, $routeId);
        if (! $route instanceof Route) {
            return new CanIRideResult(
                status: 'UNKNOWN',
                message: 'Biyahe does not currently know this route in the selected coverage area.',
                reasonCode: 'ROUTE_NOT_KNOWN',
            );
        }

        $patterns = $this->patterns($route);
        if ($patterns->isEmpty()) {
            return new CanIRideResult(
                status: 'UNKNOWN',
                message: 'Biyahe knows the route name but does not have enough usable direction data yet.',
                reasonCode: 'DIRECTION_DATA_INSUFFICIENT',
                route: $this->routeSummary($route),
                provenance: $this->provenanceFor([$route]),
            );
        }

        $boardingStops = $this->boardingStops($origin, $boardingStopId);
        $alightingStops = $this->nearbyStops($destination, false);
        if ($boardingStops->isEmpty() || $alightingStops->isEmpty()) {
            return new CanIRideResult(
                status: 'UNKNOWN',
                message: $boardingStops->isEmpty()
                    ? 'Biyahe does not have a supported boarding point near the current place yet.'
                    : 'Biyahe does not have a supported alighting point near the destination yet.',
                reasonCode: $boardingStops->isEmpty() ? 'BOARDING_ACCESS_UNSUPPORTED' : 'ALIGHTING_ACCESS_UNSUPPORTED',
                route: $this->routeSummary($route),
                provenance: $this->provenanceFor([$route]),
            );
        }

        $forward = $this->matchingOption($route, $patterns, $origin, $destination, $boardingStops, $alightingStops);
        if ($forward !== null) {
            $pattern = $forward['pattern_model'] ?? null;
            $option = $this->publicOption($this->addPedestrianAccess($forward, $origin, $destination));

            return new CanIRideResult(
                status: 'YES',
                message: 'Yes. Biyahe knows a compatible direction on this route.',
                reasonCode: 'DIRECT_SERVICE_SUPPORTED',
                route: $this->routeSummary($route),
                option: $option,
                provenance: $this->provenanceFor([$route, $pattern]),
                warnings: $option['trust'] === 'VERIFIED' ? [] : ['Route details are not fully verified yet.'],
            );
        }

        $reverseBoardingStops = $this->nearbyStops($destination, true);
        $reverseAlightingStops = $this->nearbyStops($origin, false);
        $opposite = $this->matchingOption($route, $patterns, $destination, $origin, $reverseBoardingStops, $reverseAlightingStops);
        if ($opposite !== null) {
            $pattern = $opposite['pattern_model'] ?? null;
            $option = $this->publicOption($this->addPedestrianAccess($opposite, $destination, $origin));

            return new CanIRideResult(
                status: 'DIRECTION_DEPENDENT',
                message: 'Not this direction. Biyahe knows this route in the opposite direction for these places.',
                reasonCode: 'OPPOSITE_DIRECTION_ONLY',
                route: $this->routeSummary($route),
                oppositeOption: $option,
                provenance: $this->provenanceFor([$route, $pattern]),
                warnings: $option['trust'] === 'VERIFIED' ? [] : ['The opposite-direction route details are not fully verified yet.'],
            );
        }

        return new CanIRideResult(
            status: 'NO',
            message: 'Biyahe does not currently know a supported ride on this route between these places.',
            reasonCode: 'SERVICE_NOT_SUPPORTED',
            route: $this->routeSummary($route),
            provenance: $this->provenanceFor([$route]),
        );
    }

    private function findRoute(Place $origin, ?string $routeCode, ?int $routeId): ?Route
    {
        $cacheKey = $origin->city_id.':'.($routeId !== null ? 'id:'.$routeId : 'code:'.mb_strtolower((string) $routeCode));
        if (array_key_exists($cacheKey, $this->routeCache)) {
            return $this->routeCache[$cacheKey];
        }

        $query = Route::query()
            ->with(['provenanceSource', 'vehicleType'])
            ->where('city_id', $origin->city_id);
        Trust::applyRoutable($query, 'routes');
        if ($routeId !== null) {
            $query->whereKey($routeId);
        } else {
            $query->whereRaw('LOWER(code) = ?', [mb_strtolower((string) $routeCode)]);
        }

        return $this->routeCache[$cacheKey] = $query->first();
    }

    /** @return Collection<int, RoutePattern> */
    private function patterns(Route $route): Collection
    {
        if (array_key_exists((int) $route->getKey(), $this->patternCache)) {
            return $this->patternCache[(int) $route->getKey()];
        }

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
            ])
            ->where('route_id', $route->getKey())
            ->orderBy('id');
        Trust::applyRoutable($query, 'route_patterns');

        return $this->patternCache[(int) $route->getKey()] = $query
            ->limit((int) config('biyahe.routing.max_pattern_candidates', 50))
            ->get();
    }

    /** @return Collection<int, Stop> */
    private function boardingStops(Place $origin, ?int $boardingStopId): Collection
    {
        if ($boardingStopId === null) {
            return $this->nearbyStops($origin, true);
        }

        $query = Stop::query()
            ->with(['provenanceSource', 'boardingProvenanceSource', 'nearbyPlace'])
            ->whereKey($boardingStopId)
            ->where('city_id', $origin->city_id)
            ->where('is_boarding_point', true)
            ->whereNotIn('boarding_verification_state', [
                VerificationState::DRAFT->value,
                VerificationState::STALE->value,
                VerificationState::RETIRED->value,
            ]);
        Trust::applyRoutable($query, 'stops');
        if (config('database.default') === 'pgsql') {
            $query->addSelect(['stops.*', DB::raw('ST_AsText(stops.location) AS location_wkt')]);
        }

        return $query->get();
    }

    /** @return Collection<int, Stop> */
    private function nearbyStops(Place $place, bool $boardingOnly): Collection
    {
        $cacheKey = $place->getKey().':'.($boardingOnly ? 'boarding' : 'alighting');
        if (array_key_exists($cacheKey, $this->nearbyStopCache)) {
            return $this->nearbyStopCache[$cacheKey];
        }

        return $this->nearbyStopCache[$cacheKey] = $this->nearbyStopFinder->find(
            $place,
            (float) config('biyahe.routing.max_access_radius_meters', 1000),
            (int) config('biyahe.routing.default_candidate_limit', 12),
            $boardingOnly,
        );
    }

    /** @return array<string, mixed>|null */
    private function matchingOption(
        Route $route,
        Collection $patterns,
        Place $origin,
        Place $destination,
        Collection $boardingStops,
        Collection $alightingStops,
    ): ?array {
        $boardingById = $boardingStops->keyBy(fn (Stop $stop): int => $stop->getKey());
        $alightingById = $alightingStops->keyBy(fn (Stop $stop): int => $stop->getKey());
        $options = [];

        foreach ($patterns as $pattern) {
            foreach ($pattern->patternStops as $boardingAssociation) {
                if (! Trust::isFactRoutable($boardingAssociation, ['service', 'sequence', 'boarding']) || ! $boardingAssociation->boarding_allowed) {
                    continue;
                }
                $boardingStop = $boardingById->get($boardingAssociation->stop_id);
                if (! $boardingStop instanceof Stop) {
                    continue;
                }

                foreach ($pattern->patternStops as $alightingAssociation) {
                    if (
                        ! Trust::isFactRoutable($alightingAssociation, ['service', 'sequence', 'alighting'])
                        || ! $alightingAssociation->alighting_allowed
                        || $alightingAssociation->sequence <= $boardingAssociation->sequence
                    ) {
                        continue;
                    }
                    $alightingStop = $alightingById->get($alightingAssociation->stop_id);
                    if (! $alightingStop instanceof Stop) {
                        continue;
                    }

                    $trust = $this->trustState([
                        $route,
                        $pattern,
                        $boardingStop,
                        $alightingStop,
                    ], [
                        Trust::effectiveFactSetState($boardingAssociation, ['service', 'sequence', 'boarding']),
                        Trust::effectiveFactSetState($alightingAssociation, ['service', 'sequence', 'alighting']),
                        Trust::effectiveFactState($boardingStop, 'boarding'),
                    ]);
                    $options[] = [
                        'pattern_model' => $pattern,
                        'boarding_stop_model' => $boardingStop,
                        'alighting_stop_model' => $alightingStop,
                        'pattern' => $this->patternSummary($pattern),
                        'boarding_stop' => $this->stopSummary($boardingStop),
                        'alighting_stop' => $this->stopSummary($alightingStop),
                        'boarding_association' => Trust::status($boardingAssociation),
                        'alighting_association' => Trust::status($alightingAssociation),
                        'boarding_instruction' => $this->boardingInstruction($boardingStop),
                        'driver_instruction' => 'Tell the driver: "'.$this->cueForPlace($destination).'".',
                        'get_off_guidance' => 'Get off near '.$alightingStop->name.'. The exact drop-off point or side is not verified.',
                        'stop_count' => $alightingAssociation->sequence - $boardingAssociation->sequence,
                        'boarding_sequence' => $boardingAssociation->sequence,
                        'alighting_sequence' => $alightingAssociation->sequence,
                        'access_distance_meters' => $this->distanceFromPlace($origin, $boardingStop),
                        'egress_distance_meters' => $this->distanceFromPlace($destination, $alightingStop),
                        'trust' => $trust->value,
                        'evidence' => [
                            'route' => $this->modelEvidence($route, 'ROUTE_IDENTITY'),
                            'pattern' => $this->modelEvidence($pattern, 'ROUTE_PATTERN'),
                            'boarding' => [
                                'association' => $this->patternStopEvidence($boardingAssociation),
                                'stop' => $this->stopEvidence($boardingStop),
                            ],
                            'alighting' => [
                                'association' => $this->patternStopEvidence($alightingAssociation),
                                'stop' => $this->stopEvidence($alightingStop),
                            ],
                        ],
                        'provenance' => $this->provenanceFor([
                            $route,
                            $pattern,
                            $boardingAssociation,
                            $alightingAssociation,
                            $boardingStop,
                            $alightingStop,
                        ]),
                    ];
                }
            }
        }

        usort($options, function (array $left, array $right): int {
            return (JourneyTrustState::from($left['trust'])->rank() <=> JourneyTrustState::from($right['trust'])->rank())
                ?: (($left['access_distance_meters'] + $left['egress_distance_meters']) <=> ($right['access_distance_meters'] + $right['egress_distance_meters']))
                ?: strcmp((string) $left['pattern']['id'], (string) $right['pattern']['id']);
        });

        $option = $options[0] ?? null;

        return $option;
    }

    /** @param array<string, mixed> $option @return array<string, mixed> */
    private function publicOption(array $option): array
    {
        unset($option['pattern_model']);
        unset($option['boarding_stop_model']);
        unset($option['alighting_stop_model']);

        return $option;
    }

    /** @param array<string, mixed> $option @return array<string, mixed> */
    private function addPedestrianAccess(array $option, Place $origin, Place $destination): array
    {
        $boardingStop = $option['boarding_stop_model'] ?? null;
        $alightingStop = $option['alighting_stop_model'] ?? null;
        if (! $boardingStop instanceof Stop || ! $alightingStop instanceof Stop) {
            return $option;
        }

        $option['pedestrian_access'] = [
            'to_boarding' => $this->pedestrianLeg($origin, $boardingStop, PedestrianRoutePurpose::ACCESS_WALK),
            'from_alighting' => $this->pedestrianLeg($alightingStop, $destination, PedestrianRoutePurpose::EGRESS_WALK),
        ];

        return $option;
    }

    /** @return array<string, mixed> */
    private function pedestrianLeg(Place|Stop $origin, Place|Stop $destination, PedestrianRoutePurpose $purpose): array
    {
        $originLocation = $origin->location_point;
        $destinationLocation = $destination->location_point;
        $straightLineDistance = $originLocation === null || $destinationLocation === null
            ? null
            : Geo::distanceMeters($originLocation['lat'], $originLocation['lng'], $destinationLocation['lat'], $destinationLocation['lng']);
        $maximumDistance = $purpose === PedestrianRoutePurpose::ACCESS_WALK
            ? (float) config('biyahe.pedestrian.maximum_access_walk_meters', 2000)
            : (float) config('biyahe.pedestrian.maximum_egress_walk_meters', 2000);
        $route = $originLocation === null || $destinationLocation === null
            ? null
            : $this->pedestrianRouter->route(new PedestrianRouteRequest(
                origin: $originLocation,
                destination: $destinationLocation,
                purpose: $purpose,
                maximumDistanceMeters: $maximumDistance,
            ));
        $available = $route?->status->isAvailable() ?? false;
        $noRoute = $route?->status === PedestrianRouteStatus::NO_ROUTE;

        return [
            'status' => $route?->status->value ?? 'UNAVAILABLE',
            'access_classification' => $route?->accessClassification->value ?? PedestrianAccessClassification::ROUTING_UNAVAILABLE->value,
            'distance_meters' => $available ? $route->totalDistanceMeters() : $straightLineDistance,
            'distance_type' => $available ? 'PEDESTRIAN_NETWORK' : 'STRAIGHT_LINE_ESTIMATE',
            'estimated_duration_seconds' => $available ? $route->estimatedDurationSeconds : null,
            'geometry' => $available ? $route->geometry : null,
            'source' => $route?->source,
            'precision' => $destination instanceof Stop ? ($destination->boarding_precision?->value ?? $destination->boarding_precision ?? 'UNKNOWN') : 'NOT_APPLICABLE',
            'warnings' => $noRoute
                ? ['Walking access could not be confirmed from the available map data; the distance is straight-line only.']
                : ($available ? $route->warnings : ['Straight-line distance only; no pedestrian route is claimed.']),
        ];
    }

    private function trustState(array $models, array $additionalStates = []): JourneyTrustState
    {
        $states = collect($models)
            ->filter(fn ($model): bool => $model instanceof Model)
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

    /** @return array<string, mixed> */
    private function routeSummary(Route $route): array
    {
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

    /** @return array<string, mixed> */
    private function stopSummary(Stop $stop): array
    {
        return [
            'id' => $stop->getKey(),
            'name' => $stop->name,
            'code' => $stop->code,
            'location' => $stop->location_point,
            'boarding_notes' => $stop->boarding_notes,
            'boarding_side' => $stop->boarding_side,
            'direction_note' => $stop->direction_note,
            'boarding_precision' => $stop->boarding_precision?->value ?? $stop->boarding_precision,
            'alighting_precision' => 'UNKNOWN',
            'boarding_verification' => Trust::effectiveFactState($stop, 'boarding'),
            'nearby_place' => $stop->relationLoaded('nearbyPlace') && $stop->nearbyPlace !== null
                ? ['id' => $stop->nearbyPlace->getKey(), 'name' => $stop->nearbyPlace->name]
                : null,
            'verification' => Trust::status($stop),
        ];
    }

    private function boardingInstruction(Stop $stop): string
    {
        $location = $stop->relationLoaded('nearbyPlace') && $stop->nearbyPlace !== null
            ? $stop->name.' near '.$stop->nearbyPlace->name
            : $stop->name;

        $precision = $stop->boarding_precision?->value ?? $stop->boarding_precision;
        $verified = Trust::effectiveFactState($stop, 'boarding') === VerificationState::VERIFIED->value;
        $prefix = match ($precision) {
            'EXACT_BOARDING_POINT' => $verified ? 'Walk to the verified boarding point at '.$location.'.' : 'Walk toward '.$location.'. The exact boarding point is not yet verified.',
            'BOARDING_AREA' => 'Walk to the '.$location.' boarding area. The exact bay or curbside position is not yet verified.',
            'APPROXIMATE_LOCATION' => 'Walk toward the approximate boarding area near '.$location.'.',
            default => 'The boarding location is not yet precisely verified. This reaches the mapped service area, not a confirmed curbside boarding point near '.$location.'.',
        };
        $details = array_values(array_filter([
            $stop->boarding_notes,
            $stop->boarding_side === null ? null : 'Wait on '.$stop->boarding_side.'.',
            $stop->direction_note,
        ], static fn (?string $detail): bool => $detail !== null && trim($detail) !== ''));

        return $details === [] ? $prefix : $prefix.' '.implode(' ', $details);
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
                $sources[$key] = [
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
        }

        return array_values($sources);
    }

    /** @return array<string, mixed> */
    private function modelEvidence(Model $model, string $claim): array
    {
        return [
            'claim' => $claim,
            'id' => $model->getKey(),
            'stable_id' => $model->stable_id,
            'state' => Trust::effectiveState($model),
            'verification_state' => Trust::rawState($model),
            'provenance_source_id' => $model->provenance_source_id,
        ];
    }

    /** @return array<string, mixed> */
    private function patternStopEvidence(Model $association): array
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
    private function factEvidence(Model $association, string $fact): array
    {
        return [
            'state' => Trust::evidenceState($association, $fact),
            'provenance_source_id' => $association->{$fact.'_provenance_source_id'} ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function stopEvidence(Stop $stop): array
    {
        return [
            'id' => $stop->getKey(),
            'stable_id' => $stop->stable_id,
            'state' => Trust::effectiveState($stop),
            'verification_state' => Trust::rawState($stop),
            'boarding_precision' => $stop->boarding_precision?->value ?? $stop->boarding_precision,
            'boarding_verification_state' => Trust::effectiveFactState($stop, 'boarding'),
            'provenance_source_id' => $stop->boarding_provenance_source_id,
        ];
    }
}
