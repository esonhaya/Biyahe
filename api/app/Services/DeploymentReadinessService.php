<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DeploymentReadinessService
{
    /** @return array{status: string, checks: array<string, array<string, mixed>>, generated_at: string} */
    public function check(bool $requirePostgis = true): array
    {
        $checks = [];
        $checks['application'] = ['status' => 'PASS', 'message' => 'Laravel booted.'];

        $databaseReady = false;
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $databaseReady = true;
            $checks['database'] = ['status' => 'PASS', 'message' => 'Database connection is available.'];
        } catch (Throwable) {
            $checks['database'] = ['status' => 'FAIL', 'message' => 'Database connection is unavailable.'];
        }

        $postgisReady = false;
        if ($databaseReady && config('database.default') === 'pgsql') {
            try {
                $version = DB::selectOne('select postgis_version() as version');
                $postgisReady = true;
                $checks['postgis'] = ['status' => 'PASS', 'version' => $version->version ?? null];
            } catch (Throwable) {
                $checks['postgis'] = ['status' => 'FAIL', 'message' => 'PostGIS is unavailable.'];
            }
        } else {
            $checks['postgis'] = [
                'status' => $requirePostgis ? 'FAIL' : 'BLOCKED_BY_ENVIRONMENT',
                'message' => 'The release gate requires the PostgreSQL/PostGIS connection.',
            ];
        }

        $checks['connection_mode'] = [
            'status' => config('database.default') === 'pgsql' ? 'PASS' : 'FAIL',
            'message' => 'Production readiness must not be established with SQLite.',
            'driver' => config('database.default'),
        ];

        $requiredTables = [
            'field_missions',
            'field_mission_claims',
            'field_review_actions',
            'transport_survey_sessions',
            'field_observations',
            'pedestrian_network_datasets',
            'pedestrian_network_nodes',
            'pedestrian_network_edges',
        ];
        $missingTables = $databaseReady ? array_values(array_filter($requiredTables, static fn (string $table): bool => ! Schema::hasTable($table))) : $requiredTables;
        $checks['schema'] = [
            'status' => $missingTables === [] ? 'PASS' : 'FAIL',
            'missing_tables' => $missingTables,
        ];

        $checks['migrations'] = $this->migrationCheck($databaseReady);
        $checks['active_graph'] = $this->activeGraphCheck($databaseReady, $postgisReady);
        $checks['synthetic_data'] = $this->syntheticCheck($databaseReady);
        $checks['transport_trust'] = $this->transportTrustCheck($databaseReady);
        $checks['frontend_artifacts'] = [
            'status' => is_file(base_path('../web/public/manifest.webmanifest')) && is_file(base_path('../web/public/sw.js')) ? 'PASS' : 'WARN',
            'message' => 'PWA static artifacts are present when the web workspace is available.',
        ];

        $blocking = collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'FAIL');
        if (! $requirePostgis && ($checks['postgis']['status'] ?? null) === 'FAIL') {
            $blocking = collect($checks)->except(['postgis'])->contains(fn (array $check): bool => ($check['status'] ?? null) === 'FAIL');
        }

        return [
            'status' => $blocking ? 'FAIL' : 'PASS',
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function migrationCheck(bool $databaseReady): array
    {
        if (! $databaseReady || ! Schema::hasTable('migrations')) {
            return ['status' => 'FAIL', 'message' => 'Migration repository is unavailable.'];
        }

        $expected = collect(glob(database_path('migrations/*.php')) ?: [])
            ->mapWithKeys(static fn (string $path): array => [pathinfo($path, PATHINFO_FILENAME) => $path])
            ->keys();
        $ran = DB::table('migrations')->pluck('migration')->flip();
        $pending = $expected->diff($ran->keys())->values()->all();

        return [
            'status' => $pending === [] ? 'PASS' : 'FAIL',
            'pending' => $pending,
        ];
    }

    /** @return array<string, mixed> */
    private function activeGraphCheck(bool $databaseReady, bool $postgisReady): array
    {
        if (! $databaseReady || ! Schema::hasTable('pedestrian_network_datasets')) {
            return ['status' => 'FAIL', 'message' => 'Active pedestrian graph schema is unavailable.'];
        }
        $datasets = DB::table('pedestrian_network_datasets')->where('is_active', true)->get();
        $dataset = $datasets->first();
        if ($dataset === null || $datasets->count() !== 1) {
            return ['status' => 'FAIL', 'message' => 'Exactly one active pedestrian dataset is required.', 'active_count' => $datasets->count()];
        }
        $nodes = DB::table('pedestrian_network_nodes')->where('dataset_id', $dataset->id)->count();
        $edges = DB::table('pedestrian_network_edges')->where('dataset_id', $dataset->id)->count();
        $spatial = true;
        if ($postgisReady) {
            $spatial = DB::table('pedestrian_network_nodes')->where('dataset_id', $dataset->id)->whereRaw("location IS NULL OR ST_SRID(location::geometry) <> 4326")->doesntExist()
                && DB::table('pedestrian_network_edges')->where('dataset_id', $dataset->id)->whereRaw("geometry IS NULL OR ST_SRID(geometry) <> 4326")->doesntExist();
        }

        return [
            'status' => $nodes > 0 && $edges > 0 && $spatial ? 'PASS' : 'FAIL',
            'stable_id' => $dataset->stable_id,
            'nodes' => $nodes,
            'edges' => $edges,
            'spatial_srid_4326' => $spatial,
        ];
    }

    /** @return array<string, mixed> */
    private function syntheticCheck(bool $databaseReady): array
    {
        if (! $databaseReady) {
            return ['status' => 'FAIL', 'message' => 'Cannot inspect synthetic-data boundaries.'];
        }
        $tables = [
            'field_missions',
            'transport_survey_sessions',
            'disruptions',
            'commuter_reports',
        ];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = Schema::hasColumn($table, 'is_synthetic') ? DB::table($table)->where('is_synthetic', true)->count() : 0;
        }
        $total = array_sum($counts);

        return ['status' => $total === 0 ? 'PASS' : 'FAIL', 'counts' => $counts];
    }

    /** @return array<string, mixed> */
    private function transportTrustCheck(bool $databaseReady): array
    {
        if (! $databaseReady || ! Schema::hasTable('route_pattern_stops')) {
            return ['status' => 'FAIL', 'message' => 'Transport trust schema is unavailable.'];
        }
        $unsupported = DB::table('route_pattern_stops')->where(function ($query): void {
            $query->where('service_verification_state', 'VERIFIED')
                ->orWhere('sequence_verification_state', 'VERIFIED')
                ->orWhere('boarding_verification_state', 'VERIFIED')
                ->orWhere('alighting_verification_state', 'VERIFIED');
        })->whereNull('service_provenance_source_id')->whereNull('sequence_provenance_source_id')->whereNull('boarding_provenance_source_id')->whereNull('alighting_provenance_source_id')->count();

        return ['status' => $unsupported === 0 ? 'PASS' : 'FAIL', 'unsupported_verified_claims' => $unsupported];
    }
}
