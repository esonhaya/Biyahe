<?php

namespace App\Http\Controllers\Api;

use App\Models\Place;
use App\Services\Routing\JourneyPlanner;
use App\Support\Geo;
use App\Support\Trust;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class JourneyController
{
    public function __invoke(Request $request, JourneyPlanner $planner): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'from_place_id' => ['required', 'integer', 'min:1'],
            'to_place_id' => ['required', 'integer', 'min:1'],
            'access_radius' => ['nullable', 'numeric', 'min:50', 'max:'.(int) config('biyahe.routing.max_access_radius_meters', 1000)],
            'transfer_radius' => ['nullable', 'numeric', 'min:0', 'max:'.(int) config('biyahe.routing.max_transfer_radius_meters', 600)],
            'max_transfers' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('biyahe.routing.public_max_transfers', 2)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            if ((int) $request->query('from_place_id') === (int) $request->query('to_place_id')) {
                $validator->errors()->add('to_place_id', 'Choose a different destination place.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The journey request could not be understood.',
                'error' => [
                    'code' => 'INVALID_INPUT',
                    'message' => 'Choose two different canonical places and stay within the supported search limits.',
                ],
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $origin = $this->findPlace((int) $validated['from_place_id']);
        if ($origin === null) {
            return $this->unknownPlaceResponse('origin');
        }

        $destination = $this->findPlace((int) $validated['to_place_id']);
        if ($destination === null) {
            return $this->unknownPlaceResponse('destination');
        }

        $maxTransfers = isset($validated['max_transfers'])
            ? (int) $validated['max_transfers']
            : (int) config('biyahe.routing.default_max_transfers', 2);
        $result = $planner->plan(
            origin: $origin,
            destination: $destination,
            accessRadiusMeters: isset($validated['access_radius']) ? (float) $validated['access_radius'] : null,
            transferRadiusMeters: isset($validated['transfer_radius']) ? (float) $validated['transfer_radius'] : null,
            maxTransfers: $maxTransfers,
        );
        $limit = (int) ($validated['limit'] ?? 10);
        $journeys = array_slice($result->journeys, 0, $limit);

        return response()->json([
            'data' => array_map(
                static fn ($journey): array => $journey->toArray(),
                $journeys,
            ),
            'meta' => [
                'origin' => $this->placeSummary($origin),
                'destination' => $this->placeSummary($destination),
                'result_count' => count($journeys),
                'max_transfers' => $maxTransfers,
                'distance_unit' => 'meters',
                'walking_distance_type' => 'straight_line_estimate',
                'boarding_candidates' => $result->boardingCandidates,
                'alighting_candidates' => $result->alightingCandidates,
                'no_route' => $result->hasJourneys() ? null : [
                    'code' => $result->noRouteCode,
                    'message' => $result->noRouteMessage,
                ],
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

    private function unknownPlaceResponse(string $role): JsonResponse
    {
        $label = $role === 'origin' ? 'starting' : 'destination';

        return response()->json([
            'message' => 'The '.$label.' place is not in the current Cebu coverage slice.',
            'error' => [
                'code' => strtoupper('UNKNOWN_'.$role.'_PLACE'),
                'message' => 'Choose a place from the Biyahe search suggestions.',
            ],
        ], 404);
    }

    /** @return array<string, mixed> */
    private function placeSummary(Place $place): array
    {
        return [
            'id' => $place->getKey(),
            'name' => $place->name,
            'slug' => $place->slug,
            'location' => Geo::parsePoint($place->location_wkt ?? $place->getRawOriginal('location')),
            'verification' => Trust::status($place),
        ];
    }
}
