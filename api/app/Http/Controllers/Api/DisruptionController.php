<?php

namespace App\Http\Controllers\Api;

use App\Models\Disruption;
use App\Services\Disruptions\ActiveDisruptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DisruptionController
{
    public function index(Request $request, ActiveDisruptionService $service): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'route_id' => ['nullable', 'integer', 'min:1'],
            'route_pattern_id' => ['nullable', 'integer', 'min:1'],
            'stop_id' => ['nullable', 'integer', 'min:1'],
            'place_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'south' => ['nullable', 'numeric', 'between:-90,90'],
            'west' => ['nullable', 'numeric', 'between:-180,180'],
            'north' => ['nullable', 'numeric', 'between:-90,90'],
            'east' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $viewport = ['south', 'west', 'north', 'east'];
            $provided = array_filter($viewport, static fn (string $key): bool => $request->filled($key));
            if ($provided !== [] && count($provided) !== count($viewport)) {
                $validator->errors()->add('bounds', 'Provide south, west, north, and east together.');
            }
            if ($provided !== [] && config('database.default') !== 'pgsql') {
                $validator->errors()->add('bounds', 'Map viewport filtering requires the PostGIS runtime.');
            }
            if ($provided === $viewport
                && (float) $request->input('south') > (float) $request->input('north')) {
                $validator->errors()->add('bounds', 'South must not be north of north.');
            }
            if ($provided === $viewport
                && (float) $request->input('west') > (float) $request->input('east')) {
                $validator->errors()->add('bounds', 'West must not be east of east for a bounded viewport.');
            }
        });
        if ($validator->fails()) {
            return response()->json([
                'message' => 'The disruption filter could not be understood.',
                'error' => ['code' => 'INVALID_INPUT', 'message' => 'Use bounded entity filters or a valid map viewport.'],
                'errors' => $validator->errors(),
            ], 422);
        }

        $filters = array_filter($validator->validated(), static fn ($value): bool => $value !== null);
        $limit = (int) ($filters['limit'] ?? config('biyahe.disruptions.public_limit', 50));
        unset($filters['limit']);
        $disruptions = $service->active($service->now(), $filters)->take($limit);

        return response()->json([
            'data' => $disruptions->map(fn (Disruption $disruption): array => $service->publicSummary($disruption))->values()->all(),
            'meta' => [
                'count' => $disruptions->count(),
                'current_status_only' => true,
                'temporary_overlay' => true,
            ],
        ]);
    }

    public function show(Disruption $disruption, ActiveDisruptionService $service): JsonResponse
    {
        if (! $service->isActive($disruption)) {
            return response()->json([
                'message' => 'This advisory is not currently active.',
                'error' => ['code' => 'ADVISORY_NOT_ACTIVE', 'message' => 'Operational advisories expire or resolve separately from the transport network.'],
            ], 404);
        }

        $loaded = $service->baseQuery()->whereKey($disruption->getKey())->first();
        if (! $loaded instanceof Disruption) {
            return response()->json(['message' => 'Advisory not found.'], 404);
        }

        return response()->json([
            'data' => $service->publicSummary($loaded),
            'meta' => ['temporary_overlay' => true],
        ]);
    }
}
