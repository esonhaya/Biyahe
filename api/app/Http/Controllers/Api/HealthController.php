<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        $database = ['status' => 'FAIL', 'message' => 'Database check failed.'];
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $database = ['status' => 'PASS'];
        } catch (Throwable $exception) {
            report($exception);
            $database['message'] = 'Database is unavailable.';
        }

        $postgis = ['status' => 'BLOCKED_BY_ENVIRONMENT', 'message' => 'PostGIS requires a PostgreSQL connection.'];
        if (config('database.default') === 'pgsql' && $database['status'] === 'PASS') {
            try {
                $version = DB::selectOne('select postgis_version() as version');
                $postgis = ['status' => 'PASS', 'version' => $version->version ?? null];
            } catch (Throwable $exception) {
                report($exception);
                $postgis = ['status' => 'FAIL', 'message' => 'PostGIS is unavailable.'];
            }
        }

        $healthy = $database['status'] === 'PASS' && $postgis['status'] === 'PASS';

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'application' => ['status' => 'PASS'],
            'database' => $database,
            'postgis' => $postgis,
        ], $healthy ? 200 : 503);
    }
}
