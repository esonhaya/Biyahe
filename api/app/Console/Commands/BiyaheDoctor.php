<?php

namespace App\Console\Commands;

use App\Enums\VerificationState;
use App\Services\Data\DatasetValidator;
use App\Services\Evidence\CuratedJourneySet;
use App\Services\Evidence\EvidenceCoverageReport;
use App\Services\Routing\NetworkAudit;
use App\Support\Diagnostics\DoctorCheckResult;
use App\Support\Diagnostics\DoctorReport;
use App\Support\SearchText;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BiyaheDoctor extends Command
{
    protected $signature = 'biyahe:doctor';

    protected $description = 'Check Biyahe application, PostGIS, transport network, evidence, imports, and fares.';

    private bool $hasCriticalFailure = false;

    private bool $isBlocked = false;

    private DoctorReport $report;

    public function handle(): int
    {
        $this->report = new DoctorReport;
        $this->info('BIYAHE DOCTOR V6');
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

        if (! $this->hasBatch006EvidenceSchema()) {
            $this->check('transport schema', 'FAIL', 'Batch 006 evidence migration is missing or incomplete.');

            return $this->exitCode();
        }

        try {
            $this->runRelationalChecks();
            $this->runVerificationChecks();
            $this->runEvidenceChecks();
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

    private function hasBatch006EvidenceSchema(): bool
    {
        return Schema::hasColumn('provenance_sources', 'stable_id')
            && Schema::hasColumn('provenance_sources', 'checked_at')
            && Schema::hasColumn('places', 'stable_id')
            && Schema::hasColumn('route_pattern_stops', 'provenance_source_id')
            && Schema::hasColumn('route_pattern_stops', 'verification_state')
            && Schema::hasColumn('stops', 'boarding_side')
            && Schema::hasTable('transfer_relationships')
            && Schema::hasColumn('transfer_relationships', 'stable_id')
            && Schema::hasColumn('transfer_relationships', 'from_route_pattern_id')
            && Schema::hasColumn('transfer_relationships', 'practical_note')
            && Schema::hasColumn('provenance_sources', 'evidence_scope')
            && Schema::hasColumn('provenance_sources', 'conflict_status')
            && Schema::hasColumn('provenance_sources', 'superseded_by_id')
            && Schema::hasColumn('stops', 'boarding_precision')
            && Schema::hasColumn('stops', 'boarding_verification_state')
            && Schema::hasColumn('stops', 'boarding_provenance_source_id')
            && Schema::hasColumn('route_pattern_stops', 'service_verification_state')
            && Schema::hasColumn('route_pattern_stops', 'sequence_verification_state')
            && Schema::hasColumn('route_pattern_stops', 'boarding_verification_state')
            && Schema::hasColumn('route_pattern_stops', 'alighting_verification_state')
            && Schema::hasColumn('transfer_relationships', 'review_status')
            && Schema::hasColumn('transfer_relationships', 'reviewed_at')
            && Schema::hasColumn('transfer_relationships', 'review_fingerprint');
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

        $isolatedPatterns = DB::table('route_patterns')
            ->leftJoin('route_pattern_stops', 'route_pattern_stops.route_pattern_id', '=', 'route_patterns.id')
            ->where('route_patterns.is_active', true)
            ->select('route_patterns.id')
            ->groupBy('route_patterns.id')
            ->havingRaw('COUNT(route_pattern_stops.id) < 2')
            ->count();
        $this->checkWarningCount('isolated active patterns', $isolatedPatterns, 'active patterns have fewer than two ordered stops.');

        $duplicateAssociations = DB::table('route_pattern_stops')
            ->select('route_pattern_id', 'stop_id')
            ->groupBy('route_pattern_id', 'stop_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->checkCount('duplicate service associations', $duplicateAssociations, 'route patterns associate the same stop more than once.');

        $invalidTransferPatterns = DB::table('transfer_relationships')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $pattern): void {
                    $pattern->whereNotNull('from_route_pattern_id')->whereNotExists(function (Builder $exists): void {
                        $exists->selectRaw('1')->from('route_patterns')->whereColumn('route_patterns.id', 'transfer_relationships.from_route_pattern_id');
                    });
                })->orWhere(function (Builder $pattern): void {
                    $pattern->whereNotNull('to_route_pattern_id')->whereNotExists(function (Builder $exists): void {
                        $exists->selectRaw('1')->from('route_patterns')->whereColumn('route_patterns.id', 'transfer_relationships.to_route_pattern_id');
                    });
                });
            })
            ->count();
        $this->checkCount('transfer pattern references', $invalidTransferPatterns, 'transfer relationships reference missing route patterns.');

        $associationStopMismatches = DB::table('transfer_relationships')
            ->where(function (Builder $query): void {
                $query->whereNotNull('from_route_pattern_id')->whereNotExists(function (Builder $exists): void {
                    $exists->selectRaw('1')
                        ->from('route_pattern_stops')
                        ->whereColumn('route_pattern_stops.route_pattern_id', 'transfer_relationships.from_route_pattern_id')
                        ->whereColumn('route_pattern_stops.stop_id', 'transfer_relationships.from_stop_id');
                })->orWhere(function (Builder $to): void {
                    $to->whereNotNull('to_route_pattern_id')->whereNotExists(function (Builder $exists): void {
                        $exists->selectRaw('1')
                            ->from('route_pattern_stops')
                            ->whereColumn('route_pattern_stops.route_pattern_id', 'transfer_relationships.to_route_pattern_id')
                            ->whereColumn('route_pattern_stops.stop_id', 'transfer_relationships.to_stop_id');
                    });
                });
            })
            ->count();
        $this->checkCount('transfer association integrity', $associationStopMismatches, 'transfer pattern metadata does not match its stop endpoints.');

        $staleTransferEndpoints = DB::table('transfer_relationships')
            ->join('stops AS transfer_from', 'transfer_from.id', '=', 'transfer_relationships.from_stop_id')
            ->join('stops AS transfer_to', 'transfer_to.id', '=', 'transfer_relationships.to_stop_id')
            ->where('transfer_relationships.is_active', true)
            ->where('transfer_relationships.is_demo', false)
            ->where(function (Builder $query): void {
                $states = [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value];
                $query
                    ->whereNotIn('transfer_from.verification_state', $states)
                    ->orWhereNotIn('transfer_to.verification_state', $states)
                    ->orWhere('transfer_from.is_active', false)
                    ->orWhere('transfer_to.is_active', false)
                    ->orWhere('transfer_from.is_demo', true)
                    ->orWhere('transfer_to.is_demo', true);
            })
            ->count();
        $this->checkCount('transfer endpoint routing', $staleTransferEndpoints, 'active transfers use stale, retired, inactive, or demo stop endpoints.');
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

        $verifiedAssociationsWithoutReview = DB::table('route_pattern_stops')
            ->leftJoin('provenance_sources', 'provenance_sources.id', '=', 'route_pattern_stops.provenance_source_id')
            ->where('route_pattern_stops.verification_state', VerificationState::VERIFIED->value)
            ->where(function (Builder $query): void {
                $query->whereNull('route_pattern_stops.provenance_source_id')
                    ->orWhereNull('provenance_sources.checked_at')
                    ->orWhereNull('provenance_sources.verified_by');
            })
            ->count();
        $this->checkCount('verified association provenance', $verifiedAssociationsWithoutReview, 'VERIFIED pattern-stop associations lack a checked source or reviewer.');

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

        $aliasGroups = DB::table('place_aliases')
            ->join('places', 'places.id', '=', 'place_aliases.place_id')
            ->where('places.is_demo', false)
            ->where('place_aliases.is_active', true)
            ->get(['place_aliases.alias', 'place_aliases.place_id']);
        $aliasesByNormalizedValue = [];
        foreach ($aliasGroups as $alias) {
            $aliasesByNormalizedValue[SearchText::normalize((string) $alias->alias)][(int) $alias->place_id] = true;
        }
        $aliasCollisions = collect($aliasesByNormalizedValue)
            ->filter(fn (array $placeIds): bool => count($placeIds) > 1)
            ->count();
        $this->checkWarningCount('alias collisions', $aliasCollisions, 'normalized aliases refer to more than one non-demo place and need review.');
    }

    private function runEvidenceChecks(): void
    {
        $invalidConflictMetadata = DB::table('provenance_sources')
            ->where('conflict_status', 'OPEN')
            ->where(function (Builder $query): void {
                $query->whereNull('conflict_group')->orWhere('conflict_group', '');
            })
            ->count();
        $this->checkCount('evidence conflict metadata', $invalidConflictMetadata, 'open evidence conflicts lack a conflict group.');

        $futureEvidenceDates = DB::table('provenance_sources')
            ->where(function (Builder $query): void {
                $query->whereDate('published_at', '>', now()->toDateString())
                    ->orWhereDate('observed_at', '>', now()->toDateString())
                    ->orWhereDate('checked_at', '>', now()->toDateString());
            })
            ->count();
        $this->checkCount('evidence date validity', $futureEvidenceDates, 'evidence metadata uses a future date.');

        $verifiedBlockedBySource = 0;
        foreach (['cities', 'operators', 'places', 'stops', 'routes', 'route_patterns', 'route_pattern_stops', 'transfer_relationships', 'fares'] as $table) {
            $verifiedBlockedBySource += DB::table($table)
                ->join('provenance_sources', 'provenance_sources.id', '=', $table.'.provenance_source_id')
                ->where($table.'.verification_state', VerificationState::VERIFIED->value)
                ->where(function (Builder $query): void {
                    $query->where('provenance_sources.conflict_status', 'OPEN')
                        ->orWhereNotNull('provenance_sources.superseded_by_id');
                })
                ->count();
        }
        $this->checkCount('verified conflict leakage', $verifiedBlockedBySource, 'VERIFIED facts rely on open or superseded evidence.');

        $invalidFactStates = 0;
        foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
            $invalidFactStates += DB::table('route_pattern_stops')
                ->whereNotIn($fact.'_verification_state', VerificationState::values())
                ->count();
        }
        $invalidFactStates += DB::table('stops')->whereNotIn('boarding_verification_state', VerificationState::values())->count();
        $this->checkCount('fact-state validity', $invalidFactStates, 'claim-level evidence uses an unsupported verification state.');

        $verifiedFactWithoutSource = 0;
        foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
            $verifiedFactWithoutSource += DB::table('route_pattern_stops')
                ->where($fact.'_verification_state', VerificationState::VERIFIED->value)
                ->whereNull($fact.'_provenance_source_id')
                ->count();
        }
        $verifiedFactWithoutSource += DB::table('stops')
            ->where('boarding_verification_state', VerificationState::VERIFIED->value)
            ->whereNull('boarding_provenance_source_id')
            ->count();
        $this->checkCount('verified fact provenance', $verifiedFactWithoutSource, 'VERIFIED claim-level facts lack a qualifying source reference.');

        $verifiedAggregateMismatch = DB::table('route_pattern_stops')
            ->where('verification_state', VerificationState::VERIFIED->value)
            ->where(function (Builder $query): void {
                foreach (['service', 'sequence', 'boarding', 'alighting'] as $index => $fact) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}($fact.'_verification_state', '!=', VerificationState::VERIFIED->value);
                }
            })
            ->count();
        $this->checkCount('aggregate trust consistency', $verifiedAggregateMismatch, 'VERIFIED pattern-stop associations contain unverified claim-level facts.');

        $exactBoardingWithoutEvidence = DB::table('stops')
            ->where('boarding_precision', 'EXACT_BOARDING_POINT')
            ->where(function (Builder $query): void {
                $query->where('boarding_verification_state', '!=', VerificationState::VERIFIED->value)
                    ->orWhereNull('boarding_provenance_source_id');
            })
            ->count();
        $this->checkCount('boarding precision evidence', $exactBoardingWithoutEvidence, 'exact boarding precision lacks VERIFIED boarding evidence.');

        $invalidTransferReview = DB::table('transfer_relationships')
            ->whereNotIn('review_status', ['PENDING_REVIEW', 'SUPPORTED', 'REJECTED', 'NEEDS_RECHECK'])
            ->count();
        $this->checkCount('transfer review lifecycle', $invalidTransferReview, 'transfer relationships use an unsupported review status.');
        $rejectedWithoutReason = DB::table('transfer_relationships')
            ->where('review_status', 'REJECTED')
            ->where(function (Builder $query): void {
                $query->whereNull('review_notes')->orWhere('review_notes', '');
            })
            ->count();
        $this->checkCount('rejected transfer rationale', $rejectedWithoutReason, 'rejected transfer candidates lack a retained review reason.');
        $rejectedWithoutFingerprint = DB::table('transfer_relationships')
            ->where('review_status', 'REJECTED')
            ->whereNull('review_fingerprint')
            ->count();
        $this->checkCount('rejected transfer fingerprint', $rejectedWithoutFingerprint, 'rejected transfer candidates lack a network fingerprint and cannot be safely re-evaluated after data changes.');
        $verifiedTransferWithoutSupport = DB::table('transfer_relationships')
            ->where('verification_state', VerificationState::VERIFIED->value)
            ->where('review_status', '!=', 'SUPPORTED')
            ->count();
        $this->checkCount('verified transfer review consistency', $verifiedTransferWithoutSupport, 'VERIFIED transfer facts are not in the SUPPORTED review state.');

        $activeRejectedTransfers = DB::table('transfer_relationships')
            ->where('is_active', true)
            ->whereIn('review_status', ['REJECTED', 'NEEDS_RECHECK'])
            ->count();
        $this->checkWarningCount('excluded transfer reviews', $activeRejectedTransfers, 'active transfer records are excluded until their review status is resolved.');

        $verifiedTransferWithoutSource = DB::table('transfer_relationships')
            ->where('verification_state', VerificationState::VERIFIED->value)
            ->where('review_status', 'SUPPORTED')
            ->whereNull('provenance_source_id')
            ->count();
        $this->checkCount('verified transfer evidence', $verifiedTransferWithoutSource, 'SUPPORTED VERIFIED transfers lack provenance.');

        $staleSources = DB::table('provenance_sources')
            ->whereNotNull('checked_at')
            ->whereDate('checked_at', '<', now()->subDays((int) config('biyahe.evidence.freshness.default.aging_days', 365))->toDateString())
            ->count();
        $this->checkWarningCount('evidence freshness policy', $staleSources, 'evidence sources are beyond the default freshness window and need re-review.');

        try {
            $coverage = app(EvidenceCoverageReport::class)->report(false, true);
            $this->checkWarningCount('pattern-stop evidence coverage', $coverage['pattern_stops_total'] - $coverage['pattern_stops_service_verified'], 'pattern-stop service evidence remains incomplete.');
            $this->checkWarningCount('boarding evidence coverage', $coverage['boarding_points_unknown'], 'boarding points do not yet have a declared precision.');
            $this->checkWarningCount('current fare rule evidence', $coverage['fare_rules_current'] === 0 ? 1 : 0, 'no currently effective, recently checked fare rule is recorded.');
            $this->checkWarningCount('verified fare coverage', $coverage['fare_rules_current_unverified'], 'current general fare rules are reported but remain unverified; no journey fare is calculated.');
            $this->checkWarningCount('fare evidence scope', $coverage['fare_rules_missing_scope'], 'fare claims lack matching route or vehicle-class evidence scope.');

            $factEntries = collect($coverage['fact_audit']['places'])
                ->merge($coverage['fact_audit']['routes'])
                ->merge($coverage['fact_audit']['patterns'])
                ->merge($coverage['fact_audit']['transfers'])
                ->merge($coverage['fact_audit']['fares'])
                ->map(fn (array $record): array => $record['evidence'])
                ->merge(collect($coverage['fact_audit']['pattern_stops'])->flatMap(fn (array $association): array => array_values($association['facts'])))
                ->merge(collect($coverage['fact_audit']['stops'])->flatMap(fn (array $stop): array => [$stop['location'], $stop['boarding']]));
            $verifiedWithoutScope = $factEntries->filter(function (array $entry): bool {
                if ($entry['raw_state'] !== VerificationState::VERIFIED->value) {
                    return false;
                }
                $scope = $entry['claim'];

                return $entry['provenance_source_id'] === null
                    || ! is_array($entry['evidence_scope'])
                    || ! in_array($scope, $entry['evidence_scope'], true)
                    || $entry['conflict_status'] === 'OPEN'
                    || $entry['superseded_by_id'] !== null;
            })->count();
            $this->checkCount('verified claim scope integrity', $verifiedWithoutScope, 'VERIFIED claims lack scoped provenance, conflict resolution, or current supporting evidence.');
            $verifiedFreshnessDeficiencies = $factEntries->filter(fn (array $entry): bool => $entry['raw_state'] === VerificationState::VERIFIED->value
                && $entry['provenance_source_id'] !== null
                && $entry['conflict_status'] !== 'OPEN'
                && $entry['superseded_by_id'] === null
                && in_array($entry['freshness'], ['STALE', 'UNKNOWN_FRESHNESS'], true)
            )->count();
            $this->checkWarningCount('verified claim freshness', $verifiedFreshnessDeficiencies, 'VERIFIED records rely on aging-out or undated evidence and are downgraded for trust.');
            $exactBoardingUnsupported = collect($coverage['fact_audit']['stops'])
                ->filter(fn (array $stop): bool => $stop['boarding_precision'] === 'EXACT_BOARDING_POINT'
                    && $stop['boarding']['effective_state'] !== VerificationState::VERIFIED->value)
                ->count();
            $this->checkCount('exact boarding precision provenance', $exactBoardingUnsupported, 'exact boarding points lack current, correctly scoped VERIFIED evidence.');
        } catch (Throwable $exception) {
            $this->check('evidence coverage report', 'FAIL', 'Evidence coverage report could not run: '.$exception->getMessage());
        }

        $referencedSourceIds = collect(['cities', 'operators', 'places', 'place_aliases', 'stops', 'routes', 'route_patterns', 'route_pattern_stops', 'transfer_relationships', 'fares'])
            ->flatMap(fn (string $table) => DB::table($table)->whereNotNull('provenance_source_id')->pluck('provenance_source_id'))
            ->merge(DB::table('stops')->whereNotNull('boarding_provenance_source_id')->pluck('boarding_provenance_source_id'));
        foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
            $referencedSourceIds = $referencedSourceIds->merge(
                DB::table('route_pattern_stops')->whereNotNull($fact.'_provenance_source_id')->pluck($fact.'_provenance_source_id'),
            );
        }
        $referencedSourceIds = $referencedSourceIds
            ->merge(DB::table('provenance_sources')->whereNotNull('superseded_by_id')->pluck('superseded_by_id'))
            ->unique()
            ->values();
        $orphanSources = DB::table('provenance_sources')->whereNotIn('id', $referencedSourceIds)->count();
        $this->checkWarningCount('orphan provenance', $orphanSources, 'provenance sources are not attached to a production record.');
    }

    private function runImportChecks(): void
    {
        foreach (['cebu-central-network-2026-10-05.json', 'cebu-network-expansion-2026-10-05.json'] as $datasetName) {
            $dataset = app(DatasetValidator::class)->validate(database_path('data/'.$datasetName));
            $datasetStatus = $dataset->isValid() ? 'PASS' : 'FAIL';
            $datasetMessage = $dataset->isValid()
                ? 'Dataset schema and references are valid.'
                : count($dataset->errors()).' dataset schema or reference errors.';
            $this->check('dataset '.$datasetName, $datasetStatus, $datasetMessage);
        }

        try {
            $scenarios = app(CuratedJourneySet::class)->scenarios();
            $placeSlugs = collect($scenarios)
                ->flatMap(fn (array $scenario): array => [$scenario['origin_place_slug'], $scenario['destination_place_slug']])
                ->unique()
                ->values();
            $availablePlaces = DB::table('places')
                ->whereIn('slug', $placeSlugs)
                ->where('is_active', true)
                ->where('is_demo', false)
                ->whereIn('verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value])
                ->count();
            $this->checkCount('curated journey references', max($placeSlugs->count() - $availablePlaces, 0), 'curated scenarios reference missing or ineligible places.');
        } catch (Throwable $exception) {
            $this->check('curated journey dataset', 'FAIL', 'Curated journey schema is invalid: '.$exception->getMessage());
        }

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

        $unreviewedTransfers = DB::table('transfer_relationships')
            ->where('is_active', true)
            ->where('is_demo', false)
            ->where(function (Builder $query): void {
                $query->whereNull('practical_note')->orWhere('practical_note', '');
            })
            ->where('verification_state', VerificationState::VERIFIED->value)
            ->count();
        $this->checkCount('verified transfer review notes', $unreviewedTransfers, 'VERIFIED transfers lack a practical review note.');

        $staleAssociations = DB::table('route_pattern_stops')
            ->join('route_patterns', 'route_patterns.id', '=', 'route_pattern_stops.route_pattern_id')
            ->where('route_patterns.is_active', true)
            ->whereIn('route_pattern_stops.verification_state', [VerificationState::STALE->value, VerificationState::RETIRED->value])
            ->count();
        $this->checkCount('stale association routing exposure', $staleAssociations, 'active patterns contain stale or retired service associations.');

        $invalidTransferPermissions = DB::table('transfer_relationships')
            ->join('route_pattern_stops AS from_associations', function ($join): void {
                $join->on('from_associations.route_pattern_id', '=', 'transfer_relationships.from_route_pattern_id')
                    ->on('from_associations.stop_id', '=', 'transfer_relationships.from_stop_id');
            })
            ->join('route_pattern_stops AS to_associations', function ($join): void {
                $join->on('to_associations.route_pattern_id', '=', 'transfer_relationships.to_route_pattern_id')
                    ->on('to_associations.stop_id', '=', 'transfer_relationships.to_stop_id');
            })
            ->whereNotNull('transfer_relationships.from_route_pattern_id')
            ->whereNotNull('transfer_relationships.to_route_pattern_id')
            ->where(function (Builder $query): void {
                $query->where('from_associations.alighting_allowed', false)
                    ->orWhere('to_associations.boarding_allowed', false);
            })
            ->count();
        $this->checkCount('transfer permissions', $invalidTransferPermissions, 'explicit transfers cannot alight from or board onto their referenced patterns.');

        $networkAudit = app(NetworkAudit::class)->audit(
            maxPairs: min(
                (int) config('biyahe.routing.doctor_network_audit_max_pairs', 1),
                (int) config('biyahe.routing.network_audit_max_pairs', 200),
            ),
            maxTransfers: (int) config('biyahe.routing.default_max_transfers', 2),
            maxCanIRideChecks: (int) config('biyahe.routing.doctor_network_audit_max_can_i_ride_checks', 1),
        );
        $this->check('journey smoke sample', 'PASS', sprintf(
            'Audited %d place pairs through %d transfers (%d Can-I-Ride check); found direct=%d, one-transfer=%d, two-transfer=%d.',
            $networkAudit['pairs_examined'],
            $networkAudit['max_transfers'],
            $networkAudit['can_i_ride_checked'],
            $networkAudit['direct'],
            $networkAudit['one_transfer'],
            $networkAudit['two_transfer'],
        ));
        $this->checkCount('journey invariant audit', count($networkAudit['errors']), 'journey invariant failures were found.');
        $this->checkWarningCount('known-pair coverage', $networkAudit['no_known_journey'], 'known place pairs currently have no supported journey.');
        $this->checkWarningCount('two-transfer coverage', $networkAudit['two_transfer'] === 0 ? 1 : 0, 'the bounded network audit found no two-transfer place pair.');
        $this->checkCount('routing transfer cap', $networkAudit['transfer_cap_violations'], 'journeys exceed the configured public transfer cap.');
        $this->checkCount('routing loops', $networkAudit['loops'], 'journeys repeat a route across transit legs.');
        $this->checkCount('routing direction', $networkAudit['direction_violations'], 'journeys contain backwards or empty ride sequences.');
        $this->checkCount('routing stale leakage', $networkAudit['stale_leakage'], 'journeys expose stale or retired service data.');
        $this->checkCount('routing trust leakage', $networkAudit['trust_leakage'], 'journeys elevate less-trusted legs to VERIFIED.');
        $this->checkCount('can-I-ride integrity', $networkAudit['can_i_ride_integrity_errors'], 'Can I Ride returned an invalid direction, sequence, or trust result.');
        $this->checkWarningCount('duplicate alternatives', $networkAudit['duplicate_journeys'], 'bounded routing returned duplicate commuter decisions.');
        $this->checkWarningCount('walking bounds', $networkAudit['walking_bound_violations'], 'bounded journeys exceed the configured walking burden.');

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

        $geometryJumps = DB::selectOne(
            'SELECT COUNT(*) AS jump_count
             FROM (
                 SELECT route_pattern_stops.route_pattern_id,
                        stops.location,
                        LAG(stops.location) OVER (PARTITION BY route_pattern_stops.route_pattern_id ORDER BY route_pattern_stops.sequence) AS previous_location
                 FROM route_pattern_stops
                 JOIN stops ON stops.id = route_pattern_stops.stop_id
             ) AS ordered_stops
             WHERE previous_location IS NOT NULL
               AND ST_Distance(location, previous_location) > 5000',
        );
        $this->checkWarningCount('pattern geometry jumps', (int) ($geometryJumps->jump_count ?? 0), 'consecutive mapped stops are more than 5 km apart by straight-line distance.');
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
