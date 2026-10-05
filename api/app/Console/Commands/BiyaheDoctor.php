<?php

namespace App\Console\Commands;

use App\Enums\VerificationState;
use App\Services\Data\DatasetValidator;
use App\Support\Diagnostics\DoctorCheckResult;
use App\Support\Diagnostics\DoctorReport;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BiyaheDoctor extends Command
{
    protected $signature = 'biyahe:doctor';

    protected $description = 'Check Biyahe application, PostGIS, transport network, provenance, imports, and fares.';

    private bool $hasCriticalFailure = false;

    private bool $isBlocked = false;

    private DoctorReport $report;

    public function handle(): int
    {
        $this->report = new DoctorReport;
        $this->info('BIYAHE DOCTOR V3');
        $this->check('application', 'PASS', 'Laravel booted and configuration loaded.');

        $databaseReady = $this->checkDatabase();
        $postgisReady = $this->checkPostgis($databaseReady);

        if (! $databaseReady) {
            $this->check('transport schema', 'BLOCKED', 'Database connection is required before transport checks can run.');

            return $this->exitCode();
        }

        if (! Schema::hasTable('cities')) {
            $this->check('transport schema', 'BLOCKED', 'Run the transport migrations before integrity checks.');

            return $this->exitCode();
        }

        if (! $this->hasBatch003Schema()) {
            $this->check('transport schema', 'FAIL', 'Batch 003 routing migration is missing or incomplete.');

            return $this->exitCode();
        }

        try {
            $this->runRelationalChecks();
            $this->runVerificationChecks();
            $this->runImportChecks();
            $this->runFareChecks();
            $this->runSpatialChecks($postgisReady);
            $this->runRoutingChecks($postgisReady);
        } catch (Throwable $exception) {
            report($exception);
            $this->check('doctor execution', 'FAIL', 'A Doctor query failed: '.$exception->getMessage());
        }

        return $this->exitCode();
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $this->check('database', 'PASS', 'Database connection is available.');

            return true;
        } catch (Throwable) {
            $this->check('database', 'BLOCKED', 'Database connection is unavailable; configure a running database.');
            $this->isBlocked = true;

            return false;
        }
    }

    private function checkPostgis(bool $databaseReady): bool
    {
        if (! $databaseReady || config('database.default') !== 'pgsql') {
            $this->check('postgis', 'BLOCKED', 'PostGIS checks require PostgreSQL plus the PHP pdo_pgsql extension.');
            $this->isBlocked = true;

            return false;
        }

        try {
            $version = DB::selectOne('select postgis_version() as version');
            $this->check('postgis', 'PASS', 'PostGIS '.($version->version ?? 'available').' is enabled.');

            return true;
        } catch (Throwable) {
            $this->check('postgis', 'BLOCKED', 'PostGIS extension is not available on the configured PostgreSQL connection.');
            $this->isBlocked = true;

            return false;
        }
    }

    private function hasBatch003Schema(): bool
    {
        return Schema::hasColumn('provenance_sources', 'stable_id')
            && Schema::hasColumn('provenance_sources', 'checked_at')
            && Schema::hasColumn('places', 'stable_id')
            && Schema::hasColumn('route_pattern_stops', 'provenance_source_id')
            && Schema::hasTable('transfer_relationships')
            && Schema::hasColumn('transfer_relationships', 'stable_id');
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

        $routesWithoutPatterns = DB::table('routes')
            ->where('is_active', true)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('route_patterns')->whereColumn('route_patterns.route_id', 'routes.id');
            })
            ->count();
        $this->checkCount('routes without patterns', $routesWithoutPatterns, 'active routes without route patterns.');

        $patternsWithoutStops = DB::table('route_patterns')
            ->where('is_active', true)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('route_pattern_stops')->whereColumn('route_pattern_stops.route_pattern_id', 'route_patterns.id');
            })
            ->count();
        $this->checkCount('patterns without stops', $patternsWithoutStops, 'active patterns without ordered stops.');

        $duplicateSequences = DB::table('route_pattern_stops')
            ->select('route_pattern_id', 'sequence')
            ->groupBy('route_pattern_id', 'sequence')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->checkCount('duplicate sequence positions', $duplicateSequences, 'duplicate sequence positions.');

        $consecutiveDuplicates = 0;
        foreach (DB::table('route_patterns')->pluck('id') as $patternId) {
            $patternStops = DB::table('route_pattern_stops')
                ->where('route_pattern_id', $patternId)
                ->orderBy('sequence')
                ->get(['sequence', 'stop_id']);
            $previousStopId = null;
            foreach ($patternStops as $patternStop) {
                if ($previousStopId !== null && $previousStopId === $patternStop->stop_id) {
                    $consecutiveDuplicates++;
                }
                $previousStopId = $patternStop->stop_id;
            }
        }
        $this->checkCount('consecutive duplicate stops', $consecutiveDuplicates, 'consecutive duplicate stops.');

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

        $emptyDirections = DB::table('route_patterns')->where('is_active', true)->where(function (Builder $query): void {
            $query->whereNull('direction')->orWhere('direction', '');
        })->count();
        $this->checkCount('direction completeness', $emptyDirections, 'active patterns without a direction.');

        $duplicateDirections = DB::table('route_patterns')
            ->where('is_active', true)
            ->select('route_id', 'direction')
            ->groupBy('route_id', 'direction')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->checkWarningCount('direction anomalies', $duplicateDirections, 'route/direction combinations occur more than once.');

        $routingPatterns = DB::table('route_patterns')
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value])
            ->pluck('id');
        $unusablePatterns = 0;
        foreach ($routingPatterns as $patternId) {
            $permissions = DB::table('route_pattern_stops')
                ->where('route_pattern_id', $patternId)
                ->selectRaw('COUNT(*) AS stop_count, SUM(CASE WHEN boarding_allowed THEN 1 ELSE 0 END) AS boarding_count, SUM(CASE WHEN alighting_allowed THEN 1 ELSE 0 END) AS alighting_count')
                ->first();
            if (
                $permissions === null
                || (int) $permissions->stop_count < 2
                || (int) $permissions->boarding_count < 1
                || (int) $permissions->alighting_count < 1
            ) {
                $unusablePatterns++;
            }
        }
        $this->checkCount('routing-capable patterns', $unusablePatterns, 'active patterns cannot produce a bounded ride because stops or permissions are incomplete.');

        $invalidTransferReferences = DB::table('transfer_relationships')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('stops')->whereColumn('stops.id', 'transfer_relationships.from_stop_id');
            })
            ->orWhereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('stops')->whereColumn('stops.id', 'transfer_relationships.to_stop_id');
            })
            ->count();
        $this->checkCount('transfer references', $invalidTransferReferences, 'transfer relationships reference missing stops.');

        $invalidTransferCities = DB::table('transfer_relationships')
            ->join('stops AS transfer_from', 'transfer_from.id', '=', 'transfer_relationships.from_stop_id')
            ->join('stops AS transfer_to', 'transfer_to.id', '=', 'transfer_relationships.to_stop_id')
            ->where(function (Builder $query): void {
                $query->whereColumn('transfer_from.city_id', '!=', 'transfer_relationships.city_id')
                    ->orWhereColumn('transfer_to.city_id', '!=', 'transfer_relationships.city_id');
            })
            ->count();
        $this->checkCount('transfer city scope', $invalidTransferCities, 'transfer relationships cross city scopes.');
    }

    private function runVerificationChecks(): void
    {
        $transportTables = ['cities', 'operators', 'places', 'stops', 'routes', 'route_patterns', 'transfer_relationships', 'fares'];
        $invalidStates = 0;
        foreach ($transportTables as $table) {
            $invalidStates += DB::table($table)->whereNotIn('verification_state', VerificationState::values())->count();
        }
        $this->checkCount('verification-state validity', $invalidStates, 'records with an unsupported verification state.');

        $missingSources = 0;
        foreach ($transportTables as $table) {
            $missingSources += DB::table($table)->where('is_demo', false)->whereNull('provenance_source_id')->count();
        }
        $this->checkCount('provenance integrity', $missingSources, 'non-demo transport records without provenance.');

        $verifiedWithoutReview = 0;
        foreach ($transportTables as $table) {
            $verifiedWithoutReview += DB::table($table)
                ->leftJoin('provenance_sources', 'provenance_sources.id', '=', $table.'.provenance_source_id')
                ->where($table.'.verification_state', VerificationState::VERIFIED->value)
                ->where(function (Builder $query) use ($table): void {
                    $query->whereNull($table.'.provenance_source_id')
                        ->orWhereNull('provenance_sources.checked_at')
                        ->orWhereNull('provenance_sources.verified_by');
                })
                ->count();
        }
        $this->checkCount('verified provenance review', $verifiedWithoutReview, 'VERIFIED records lack a checked source or reviewer.');

        $verifiedDemo = 0;
        foreach ($transportTables as $table) {
            $verifiedDemo += DB::table($table)->where('is_demo', true)->where('verification_state', VerificationState::VERIFIED->value)->count();
        }
        $this->checkCount('demo data distinction', $verifiedDemo, 'demo records incorrectly marked VERIFIED.');

        $activeRetired = 0;
        foreach (['cities', 'operators', 'places', 'stops', 'routes', 'route_patterns', 'transfer_relationships'] as $table) {
            $activeRetired += DB::table($table)->where('is_active', true)->where('verification_state', VerificationState::RETIRED->value)->count();
        }
        $this->checkCount('retired exposure', $activeRetired, 'retired records remain active.');

        $activeStale = 0;
        foreach (['places', 'stops', 'routes', 'route_patterns', 'transfer_relationships'] as $table) {
            $activeStale += DB::table($table)
                ->where('is_active', true)
                ->where('verification_state', VerificationState::STALE->value)
                ->count();
        }
        $this->checkCount('stale routing exposure', $activeStale, 'stale records remain active in the routing surface.');

        $staleSources = DB::table('provenance_sources')
            ->whereNotNull('checked_at')
            ->whereDate('checked_at', '<', now()->subYear()->toDateString())
            ->count();
        $this->checkWarningCount('provenance freshness', $staleSources, 'provenance sources have not been checked within the one-year review policy.');
    }

    private function runImportChecks(): void
    {
        $dataset = app(DatasetValidator::class)->validate(database_path('data/cebu-central-network-2026-10-05.json'));
        $datasetStatus = $dataset->isValid() ? 'PASS' : 'FAIL';
        $datasetMessage = $dataset->isValid()
            ? 'Reviewed dataset schema and references are valid.'
            : count($dataset->errors()).' dataset schema or reference errors.';
        $this->check('dataset schema and references', $datasetStatus, $datasetMessage);

        $importedTables = ['provenance_sources', 'cities', 'operators', 'places', 'place_aliases', 'stops', 'routes', 'route_patterns', 'route_pattern_stops', 'transfer_relationships', 'fares'];
        $missingStableIds = 0;
        foreach (['cities', 'operators', 'places', 'stops', 'routes', 'route_patterns', 'fares'] as $table) {
            $missingStableIds += DB::table($table)->where('is_demo', false)->whereNull('stable_id')->count();
        }
        $missingStableIds += DB::table('place_aliases')
            ->join('places', 'places.id', '=', 'place_aliases.place_id')
            ->where('places.is_demo', false)
            ->whereNull('place_aliases.stable_id')
            ->count();
        $missingStableIds += DB::table('route_pattern_stops')
            ->join('route_patterns', 'route_patterns.id', '=', 'route_pattern_stops.route_pattern_id')
            ->where('route_patterns.is_demo', false)
            ->whereNull('route_pattern_stops.stable_id')
            ->count();
        $missingStableIds += DB::table('transfer_relationships')
            ->where('is_demo', false)
            ->whereNull('stable_id')
            ->count();
        $this->checkCount('import identifiers', $missingStableIds, 'non-demo records lack stable import identifiers.');

        $duplicateStableIds = 0;
        foreach ($importedTables as $table) {
            $duplicateStableIds += DB::table($table)
                ->select('stable_id')
                ->whereNotNull('stable_id')
                ->groupBy('stable_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();
        }
        $this->checkCount('duplicate stable identifiers', $duplicateStableIds, 'duplicate stable identifiers exist.');
    }

    private function runFareChecks(): void
    {
        $invalidFares = DB::table('fares')
            ->where('amount', '<', 0)
            ->orWhere(function (Builder $query): void {
                $query->whereNotNull('effective_until')->whereColumn('effective_until', '<', 'effective_from');
            })
            ->orWhere(function (Builder $query): void {
                $query->whereNotNull('max_distance_km')->whereNotNull('min_distance_km')->whereColumn('max_distance_km', '<', 'min_distance_km');
            })
            ->count();
        $this->checkCount('fare integrity', $invalidFares, 'fare records have invalid numeric values, dates, or distance ranges.');

        $contradictoryFares = 0;
        $fares = DB::table('fares')
            ->where('verification_state', '!=', VerificationState::RETIRED->value)
            ->orderBy('route_id')
            ->orderBy('vehicle_type_id')
            ->orderBy('fare_class')
            ->orderBy('effective_from')
            ->get();
        foreach ($fares as $fareIndex => $fare) {
            for ($nextIndex = $fareIndex + 1; $nextIndex < $fares->count(); $nextIndex++) {
                $nextFare = $fares[$nextIndex];
                if ($nextFare->route_id !== $fare->route_id || $nextFare->vehicle_type_id !== $fare->vehicle_type_id || $nextFare->fare_class !== $fare->fare_class) {
                    break;
                }
                $fareEnd = $fare->effective_until ?? '9999-12-31';
                $nextEnd = $nextFare->effective_until ?? '9999-12-31';
                if ($fare->effective_from <= $nextEnd && $nextFare->effective_from <= $fareEnd) {
                    $contradictoryFares++;
                }
            }
        }
        $this->checkCount('fare conflicts', $contradictoryFares, 'active fare records have overlapping effective dates.');
    }

    private function runSpatialChecks(bool $postgisReady): void
    {
        if (! $postgisReady) {
            $this->check('spatial integrity', 'BLOCKED', 'PostGIS runtime is required to validate geography, route geometry, and spatial indexes.');

            return;
        }

        $invalidPoints = 0;
        foreach (['places', 'stops'] as $table) {
            $invalidPoints += DB::table($table)->whereRaw("location IS NULL OR ST_SRID(location::geometry) <> 4326 OR ST_GeometryType(location::geometry) <> 'ST_Point' OR ST_IsEmpty(location::geometry) OR ST_X(location::geometry) NOT BETWEEN -180 AND 180 OR ST_Y(location::geometry) NOT BETWEEN -90 AND 90")->count();
        }
        $this->checkCount('point coordinates', $invalidPoints, 'places or stops with invalid WGS 84 points.');

        $invalidGeometry = DB::table('route_patterns')->whereRaw("geometry IS NULL OR ST_SRID(geometry) <> 4326 OR ST_GeometryType(geometry) <> 'ST_MultiLineString' OR ST_IsEmpty(geometry) OR NOT ST_IsValid(geometry)")->count();
        $this->checkCount('route geometry validity', $invalidGeometry, 'route patterns have empty, invalid, or wrongly typed geometry.');

        $activeInvalidGeometry = DB::table('route_patterns')->where('is_active', true)->whereRaw('geometry IS NULL OR ST_IsEmpty(geometry) OR NOT ST_IsValid(geometry)')->count();
        $this->checkCount('active pattern geometry', $activeInvalidGeometry, 'active patterns lack usable geometry.');

        $expectedIndexes = ['places_location_gix', 'stops_location_gix', 'route_patterns_geometry_gix'];
        $availableIndexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND indexname IN ('places_location_gix', 'stops_location_gix', 'route_patterns_geometry_gix')"))->pluck('indexname')->all();
        $missingIndexes = count(array_diff($expectedIndexes, $availableIndexes));
        $this->checkCount('spatial indexes', $missingIndexes, 'expected PostGIS GiST indexes are missing.');
    }

    private function runRoutingChecks(bool $postgisReady): void
    {
        $invalidTransferDistances = DB::table('transfer_relationships')
            ->where('distance_meters', '<', 0)
            ->orWhere('distance_meters', '>', (float) config('biyahe.routing.max_transfer_radius_meters', 600))
            ->count();
        $this->checkCount('transfer distance bounds', $invalidTransferDistances, 'transfer relationships exceed the supported walking-transfer bound.');

        $unusablePermissions = DB::table('route_patterns')
            ->join('route_pattern_stops', 'route_pattern_stops.route_pattern_id', '=', 'route_patterns.id')
            ->where('route_patterns.is_active', true)
            ->whereIn('route_patterns.verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value])
            ->select('route_patterns.id')
            ->groupBy('route_patterns.id')
            ->havingRaw('SUM(CASE WHEN route_pattern_stops.boarding_allowed THEN 1 ELSE 0 END) = 0 OR SUM(CASE WHEN route_pattern_stops.alighting_allowed THEN 1 ELSE 0 END) = 0')
            ->count();
        $this->checkCount('boarding and alighting permissions', $unusablePermissions, 'active patterns have no usable boarding or alighting permission.');

        $verifiedServiceWithoutProvenance = DB::table('route_patterns')
            ->leftJoin('provenance_sources', 'provenance_sources.id', '=', 'route_patterns.provenance_source_id')
            ->where('route_patterns.verification_state', VerificationState::VERIFIED->value)
            ->where(function (Builder $query): void {
                $query->whereNull('route_patterns.provenance_source_id')
                    ->orWhereNull('provenance_sources.checked_at')
                    ->orWhereNull('provenance_sources.verified_by');
            })
            ->count();
        $verifiedServiceWithoutProvenance += DB::table('route_pattern_stops')
            ->join('route_patterns', 'route_patterns.id', '=', 'route_pattern_stops.route_pattern_id')
            ->leftJoin('provenance_sources', 'provenance_sources.id', '=', 'route_pattern_stops.provenance_source_id')
            ->where('route_patterns.verification_state', VerificationState::VERIFIED->value)
            ->where(function (Builder $query): void {
                $query->whereNull('route_pattern_stops.provenance_source_id')
                    ->orWhereNull('provenance_sources.checked_at')
                    ->orWhereNull('provenance_sources.verified_by');
            })
            ->count();
        $this->checkCount('verified service provenance', $verifiedServiceWithoutProvenance, 'verified route services or ordered stops lack adequate provenance.');

        $verifiedTransferWithoutProvenance = DB::table('transfer_relationships')
            ->leftJoin('provenance_sources', 'provenance_sources.id', '=', 'transfer_relationships.provenance_source_id')
            ->where('transfer_relationships.verification_state', VerificationState::VERIFIED->value)
            ->where(function (Builder $query): void {
                $query->whereNull('transfer_relationships.provenance_source_id')
                    ->orWhereNull('provenance_sources.checked_at')
                    ->orWhereNull('provenance_sources.verified_by');
            })
            ->count();
        $this->checkCount('verified transfer provenance', $verifiedTransferWithoutProvenance, 'verified transfer relationships lack adequate provenance.');

        if (! $postgisReady) {
            $this->check('pattern stop geography', 'BLOCKED', 'PostGIS runtime is required to compare ordered stops with route geometry.');

            return;
        }

        $geometryMismatches = DB::selectOne(
            'SELECT COUNT(*) AS mismatch_count
             FROM route_pattern_stops AS pattern_stops
             JOIN route_patterns AS patterns ON patterns.id = pattern_stops.route_pattern_id
             JOIN stops ON stops.id = pattern_stops.stop_id
             WHERE patterns.is_active = true
               AND patterns.geometry IS NOT NULL
               AND stops.location IS NOT NULL
               AND NOT ST_DWithin(stops.location, patterns.geometry::geography, 2000)',
        );
        $this->checkWarningCount('pattern stop geography', (int) ($geometryMismatches->mismatch_count ?? 0), 'ordered stops sit more than 2 km from their pattern geometry.');
    }

    private function checkCount(string $label, int $count, string $failureMessage): void
    {
        $this->check($label, $count === 0 ? 'PASS' : 'FAIL', $count === 0 ? 'No issues found.' : $count.' '.$failureMessage);
    }

    private function checkWarningCount(string $label, int $count, string $warningMessage): void
    {
        $this->check($label, $count === 0 ? 'PASS' : 'WARN', $count === 0 ? 'No issues found.' : $count.' '.$warningMessage);
    }

    private function check(string $label, string $status, string $message): void
    {
        $this->line(sprintf('%-34s %s — %s', $label, $status, $message));
        $id = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '.', $label), '.'));
        $this->report->add(new DoctorCheckResult($id, $label, $status, $message));
        if ($status === 'FAIL') {
            $this->hasCriticalFailure = true;
        }
        if ($status === 'BLOCKED') {
            $this->isBlocked = true;
        }
    }

    private function exitCode(): int
    {
        $this->line(sprintf(
            'summary: PASS=%d WARN=%d FAIL=%d BLOCKED=%d',
            $this->report->count('PASS'),
            $this->report->count('WARN'),
            $this->report->count('FAIL'),
            $this->report->count('BLOCKED'),
        ));
        if ($this->hasCriticalFailure) {
            return self::FAILURE;
        }
        if ($this->isBlocked) {
            return self::INVALID;
        }

        return self::SUCCESS;
    }
}
