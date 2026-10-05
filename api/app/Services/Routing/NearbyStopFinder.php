<?php

namespace App\Services\Routing;

use App\Enums\VerificationState;
use App\Models\Place;
use App\Models\Stop;
use App\Support\Geo;
use App\Support\Trust;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NearbyStopFinder
{
    /** @return Collection<int, Stop> */
    public function find(Place $place, float $radiusMeters, int $limit, bool $boardingOnly): Collection
    {
        $location = $place->location_point;
        if ($location === null) {
            return collect();
        }

        $radiusMeters = min(
            max($radiusMeters, 1),
            (float) config('biyahe.routing.max_access_radius_meters', 1000),
        );
        $limit = min(
            max($limit, 1),
            (int) config('biyahe.routing.default_candidate_limit', 12),
        );

        $query = Stop::query()
            ->with(['provenanceSource', 'boardingProvenanceSource', 'nearbyPlace'])
            ->where('stops.city_id', $place->city_id)
            ->where('stops.is_active', true)
            ->where('stops.is_demo', false)
            ->whereIn('stops.verification_state', [
                VerificationState::VERIFIED->value,
                VerificationState::UNVERIFIED->value,
            ])
            ->whereNotNull('stops.location');
        Trust::applyRoutable($query, 'stops');

        if ($boardingOnly) {
            $query
                ->with('boardingProvenanceSource')
                ->where('stops.is_boarding_point', true)
                ->whereNotIn('stops.boarding_verification_state', [
                    VerificationState::DRAFT->value,
                    VerificationState::STALE->value,
                    VerificationState::RETIRED->value,
                ]);
        }

        if (config('database.default') === 'pgsql') {
            $point = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';
            $query
                ->addSelect(['stops.*', DB::raw('ST_AsText(stops.location) AS location_wkt')])
                ->whereRaw("ST_DWithin(stops.location, {$point}, ?)", [$location['lng'], $location['lat'], $radiusMeters])
                ->selectRaw("ST_Distance(stops.location, {$point}) AS distance_meters", [$location['lng'], $location['lat']])
                ->orderBy('distance_meters')
                ->orderBy('stops.id')
                ->limit($limit);

            return $query->get();
        }

        return $query->get()
            ->map(function (Stop $stop) use ($location): Stop {
                $stopLocation = $stop->location_point;
                $stop->distance_meters = $stopLocation === null
                    ? INF
                    : Geo::distanceMeters($location['lat'], $location['lng'], $stopLocation['lat'], $stopLocation['lng']);

                return $stop;
            })
            ->filter(fn (Stop $stop): bool => (float) $stop->distance_meters <= $radiusMeters)
            ->sort(function (Stop $left, Stop $right): int {
                return ((float) $left->distance_meters <=> (float) $right->distance_meters)
                    ?: ($left->getKey() <=> $right->getKey());
            })
            ->take($limit)
            ->values();
    }
}
