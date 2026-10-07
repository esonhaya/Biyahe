<?php

namespace App\Http\Controllers\Api;

use App\Services\Disruptions\PulseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PulseController
{
    public function __invoke(Request $request, PulseService $service): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'filter' => ['nullable', 'string', 'in:ALL,ROUTES,STOPS,AREAS,MAJOR'],
            'route_id' => ['nullable', 'integer', 'min:1'],
            'stop_id' => ['nullable', 'integer', 'min:1'],
            'place_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'The Pulse filter could not be understood.',
                'error' => ['code' => 'INVALID_PULSE_FILTER', 'message' => 'Use one bounded Pulse filter and optional entity context.'],
                'errors' => $validator->errors(),
            ], 422);
        }

        return response()->json(['data' => $service->summary($validator->validated())]);
    }
}
