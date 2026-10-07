<?php

namespace App\Http\Controllers\Api;

use App\Models\FieldMission;
use App\Models\TransportSurveySession;
use App\Models\User;
use App\Services\FieldSurveys\FieldMissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class FieldMissionController
{
    public function index(Request $request, FieldMissionService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->listFor($this->operator($request)),
            'meta' => ['snapshot_schema_version' => FieldMissionService::SNAPSHOT_VERSION, 'location_tracking' => 'EXPLICIT_PER_OBSERVATION_ONLY'],
        ]);
    }

    public function show(Request $request, FieldMission $mission, FieldMissionService $service): JsonResponse
    {
        $this->assertMission($mission);

        return response()->json(['data' => $service->snapshot($mission, $this->operator($request))]);
    }

    public function start(Request $request, FieldMission $mission, FieldMissionService $service): JsonResponse
    {
        $this->assertMission($mission);
        $session = $service->startSession($mission, $this->operator($request));

        return response()->json(['data' => $service->snapshot($mission->fresh(['claims', 'sessions']), $this->operator($request)), 'session_id' => $session->getKey()], 201);
    }

    public function sync(Request $request, TransportSurveySession $session, FieldMissionService $service): JsonResponse
    {
        $payload = $request->input('observations');
        if (! is_array($payload) || array_is_list($payload) === false) {
            return response()->json(['message' => 'Observations must be a bounded JSON array.', 'error' => ['code' => 'INVALID_FIELD_OUTBOX']], 422);
        }
        $result = $service->sync($session, $payload, $this->operator($request));

        return response()->json(['data' => $result, 'meta' => ['server_receipt' => true, 'canonical_transport_data_changed' => false]]);
    }

    public function submit(Request $request, TransportSurveySession $session, FieldMissionService $service): JsonResponse
    {
        $saved = $service->submitObservation($session, $request->all(), $this->operator($request));

        return response()->json([
            'data' => ['stable_id' => $saved['observation']->stable_id, 'status' => 'SUBMITTED', 'idempotent' => $saved['idempotent']],
            'meta' => ['server_receipt' => true, 'canonical_transport_data_changed' => false],
        ], $saved['idempotent'] ? 200 : 201);
    }

    public function complete(Request $request, TransportSurveySession $session, FieldMissionService $service): JsonResponse
    {
        $completed = $service->submitSession($session, $this->operator($request));

        return response()->json(['data' => ['id' => $completed->getKey(), 'status' => 'SUBMITTED'], 'meta' => ['server_receipt' => true]]);
    }

    private function operator(Request $request): User
    {
        $operator = $request->attributes->get('field_operator');
        if (! $operator instanceof User) {
            throw ValidationException::withMessages(['operator' => 'An authorized field operator is required.']);
        }

        return $operator;
    }

    private function assertMission(FieldMission $mission): void
    {
        if ($mission->is_synthetic) {
            abort(404);
        }
    }
}
