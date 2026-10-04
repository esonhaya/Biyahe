<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BiyaheDoctor extends Command
{
    protected $signature = 'biyahe:doctor';

    protected $description = 'Check Biyahe application, PostGIS, and transport-data integrity.';

    private bool $hasCriticalFailure = false;

    private bool $isBlocked = false;

    public function handle(): int
    {
        $this->info('BIYAHE DOCTOR');
        $this->check('application', 'PASS', 'Laravel booted and configuration loaded.');

        $databaseReady = false;
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $databaseReady = true;
            $this->check('database', 'PASS', 'Database connection is available.');
        } catch (Throwable) {
            $this->check('database', 'BLOCKED_BY_ENVIRONMENT', 'Database connection is unavailable; configure a running database.');
            $this->isBlocked = true;
        }

        $postgisReady = false;
        if (! $databaseReady || config('database.default') !== 'pgsql') {
            $this->check('postgis', 'BLOCKED_BY_ENVIRONMENT', 'PostGIS checks require PostgreSQL plus the PHP pdo_pgsql extension.');
            $this->isBlocked = true;
        } else {
            try {
                $version = DB::selectOne('select postgis_version() as version');
                $postgisReady = true;
                $this->check('postgis', 'PASS', 'PostGIS '.($version->version ?? 'available').'.');
            } catch (Throwable) {
                $this->check('postgis', 'BLOCKED_BY_ENVIRONMENT', 'PostGIS extension is not available on the configured PostgreSQL connection.');
                $this->isBlocked = true;
            }
        }

        if (! $databaseReady || ! Schema::hasTable('cities')) {
            $this->check('transport schema', 'BLOCKED_BY_ENVIRONMENT', 'Run the transport migrations on a configured database before integrity checks.');
            $this->isBlocked = true;

            return $this->exitCode();
        }

        $this->runRelationalChecks();
        $this->runSpatialChecks($postgisReady);

        return $this->exitCode();
    }

    private function runRelationalChecks(): void
    {
        $this->checkCount('city references', DB::table('places')->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('cities')->whereColumn('cities.id', 'places.city_id');
        })->count(), 'orphan place city references.');

        $this->checkCount('alias ownership', DB::table('place_aliases')->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('places')->whereColumn('places.id', 'place_aliases.place_id');
        })->count(), 'orphan place aliases.');

        $this->checkCount('pattern-stop ownership', DB::table('route_pattern_stops')->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('route_patterns')->whereColumn('route_patterns.id', 'route_pattern_stops.route_pattern_id');
        })->orWhereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('stops')->whereColumn('stops.id', 'route_pattern_stops.stop_id');
        })->count(), 'orphan route-pattern stops.');

        $this->checkCount('pattern parent routes', DB::table('route_patterns')->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('routes')->whereColumn('routes.id', 'route_patterns.route_id');
        })->count(), 'route patterns without a parent route.');

        $duplicateSequences = DB::table('route_pattern_stops')
            ->select('route_pattern_id', 'sequence')
            ->groupBy('route_pattern_id', 'sequence')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->checkCount('duplicate sequence positions', $duplicateSequences, 'duplicate sequence positions.');

        $invalidStates = 0;
        foreach (['cities', 'operators', 'places', 'stops', 'routes', 'route_patterns', 'fares'] as $table) {
            if (Schema::hasTable($table)) {
                $invalidStates += DB::table($table)->whereNotIn('verification_state', ['DRAFT', 'VERIFIED', 'STALE', 'RETIRED'])->count();
            }
        }
        $this->checkCount('verification-state validity', $invalidStates, 'records with an unsupported verification state.');

        $missingSources = 0;
        foreach (['places', 'stops', 'routes', 'route_patterns', 'fares'] as $table) {
            $missingSources += DB::table($table)->whereNull('provenance_source_id')->where('is_demo', false)->count();
        }
        $this->checkCount('provenance integrity', $missingSources, 'non-demo transport records without provenance.');

        $verifiedDemo = 0;
        foreach (['cities', 'operators', 'places', 'stops', 'routes', 'route_patterns', 'fares'] as $table) {
            $verifiedDemo += DB::table($table)->where('is_demo', true)->where('verification_state', 'VERIFIED')->count();
        }
        $this->checkCount('demo data distinction', $verifiedDemo, 'demo records incorrectly marked VERIFIED.');

        $invalidFares = DB::table('fares')
            ->where('amount', '<', 0)
            ->orWhere(function (Builder $query): void {
                $query->whereNotNull('effective_until')->whereColumn('effective_until', '<', 'effective_from');
            })
            ->count();
        $this->checkCount('fare integrity', $invalidFares, 'fare records with invalid amount or dates.');

        $invalidSequences = 0;
        foreach (DB::table('route_patterns')->pluck('id') as $patternId) {
            $sequences = DB::table('route_pattern_stops')->where('route_pattern_id', $patternId)->orderBy('sequence')->pluck('sequence')->all();
            foreach ($sequences as $index => $sequence) {
                if ((int) $sequence !== $index + 1) {
                    $invalidSequences++;
                }
            }
        }
        $this->checkCount('contiguous stop ordering', $invalidSequences, 'patterns with non-contiguous stop sequences.');
    }

    private function runSpatialChecks(bool $postgisReady): void
    {
        if (! $postgisReady) {
            $this->check('spatial integrity', 'BLOCKED_BY_ENVIRONMENT', 'PostGIS runtime is required to validate geography and route geometry.');

            return;
        }

        $invalidPoints = DB::table('places')->whereRaw('location IS NULL OR ST_IsEmpty(location::geometry) OR ST_X(location::geometry) NOT BETWEEN -180 AND 180 OR ST_Y(location::geometry) NOT BETWEEN -90 AND 90')->count()
            + DB::table('stops')->whereRaw('location IS NULL OR ST_IsEmpty(location::geometry) OR ST_X(location::geometry) NOT BETWEEN -180 AND 180 OR ST_Y(location::geometry) NOT BETWEEN -90 AND 90')->count();
        $this->checkCount('point coordinates', $invalidPoints, 'places or stops with invalid WGS 84 points.');

        $invalidGeometry = DB::table('route_patterns')->whereRaw('geometry IS NULL OR ST_IsEmpty(geometry) OR NOT ST_IsValid(geometry)')->count();
        $this->checkCount('route geometry validity', $invalidGeometry, 'route patterns with empty or invalid geometry.');
    }

    private function checkCount(string $label, int $count, string $failureMessage): void
    {
        $this->check($label, $count === 0 ? 'PASS' : 'FAIL', $count === 0 ? 'No issues found.' : $count.' '.$failureMessage);
        if ($count !== 0) {
            $this->hasCriticalFailure = true;
        }
    }

    private function check(string $label, string $status, string $message): void
    {
        $this->line(sprintf('%-30s %s — %s', $label, $status, $message));
    }

    private function exitCode(): int
    {
        if ($this->hasCriticalFailure) {
            return self::FAILURE;
        }

        return $this->isBlocked ? self::INVALID : self::SUCCESS;
    }
}
