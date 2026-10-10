<?php

namespace App\Http\Controllers\Api;

use App\Services\DeploymentReadinessService;
use Illuminate\Http\JsonResponse;

final class ReadinessController
{
    public function __invoke(DeploymentReadinessService $readiness): JsonResponse
    {
        $result = $readiness->check();

        return response()->json($result, $result['status'] === 'PASS' ? 200 : 503);
    }
}
