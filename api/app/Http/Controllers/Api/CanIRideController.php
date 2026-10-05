<?php

namespace App\Http\Controllers\Api;

use App\Models\Place;
use App\Services\Routing\CanIRideChecker;
use App\Support\Geo;
use App\Support\Trust;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CanIRideController
{
    public function __invoke(Request $request, CanIRideChecker $checker): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'route_code' => ['nullable', 'string', 'max:40'],
            'route_id' => ['nullable', 'integer', 'min:1'],
            'from_place_id' => ['required', 'integer', 'min:1'],
            'to_place_id' => ['required', 'integer', 'min:1'],
            'boarding_stop_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $hasRouteCode = trim((string) $request->query('route_code')) !== '';
            $hasRouteId = $request->filled('route_id');
            if (! $hasRouteCode && ! $hasRouteId) {
                $validator->errors()->add('route_code', 'Provide a route code or route ID.');
            }
            if ($hasRouteCode && $hasRouteId) {
                $validator->errors()->add('route_code', 'Provide either a route code or route ID, not both.');
            }
            if ((int) $request->query('from_place_id') === (int) $request->query('to_place_id')) {
                $validator->errors()->add('to_place_id', 'Choose a different destination place.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The Can I Ride request could not be understood.',
                'error' => [
                    'code' => 'INVALID_INPUT',
                    'message' => 'Provide one route and two different canonical places.',
                ],
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $origin = $this->findPlace((int) $validated['from_place_id']);
        if ($origin === null) {
            return response()->json([
                'message' => 'The starting place is not in the current Cebu coverage slice.',
                'error' => ['code' => 'UNKNOWN_ORIGIN_PLACE', 'message' => 'Choose a place from the Biyahe search suggestions.'],
            ], 404);
        }
        $destination = $this->findPlace((int) $validated['to_place_id']);
        if ($destination === null) {
            return response()->json([
                'message' => 'The destination place is not in the current Cebu coverage slice.',
                'error' => ['code' => 'UNKNOWN_DESTINATION_PLACE', 'message' => 'Choose a place from the Biyahe search suggestions.'],
            ], 404);
        }

        $result = $checker->check(
            origin: $origin,
            destination: $destination,
            routeCode: isset($validated['route_code']) ? trim((string) $validated['route_code']) : null,
            routeId: isset($validated['route_id']) ? (int) $validated['route_id'] : null,
            boardingStopId: isset($validated['boarding_stop_id']) ? (int) $validated['boarding_stop_id'] : null,
        );

        return response()->json([
            'data' => [
                'origin' => $this->placeSummary($origin),
                'destination' => $this->placeSummary($destination),
                ...$result->toArray(),
            ],
            'meta' => [
                'distance_unit' => 'meters',
                'walking_distance_type' => 'straight_line_estimate',
                'access_radius_meters' => (int) config('biyahe.routing.max_access_radius_meters', 1000),
            ],
        ]);
    }

    private function findPlace(int $placeId): ?Place
    {
        $query = Place::query()
            ->with(['aliases', 'provenanceSource'])
            ->whereKey($placeId);
        Trust::applyRoutable($query, 'places');
        if (config('database.default') === 'pgsql') {
            $query->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')]);
        }

        return $query->first();
    }

    /** @return array<string, mixed> */
    private function placeSummary(Place $place): array
    {
        return [
            'type' => 'PLACE',
            'id' => $place->getKey(),
            'name' => $place->name,
            'slug' => $place->slug,
            'location' => Geo::parsePoint($place->location_wkt ?? $place->getRawOriginal('location')),
            'verification' => Trust::status($place),
        ];
    }
}
