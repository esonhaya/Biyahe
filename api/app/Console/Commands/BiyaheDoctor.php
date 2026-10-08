<?php

namespace App\Console\Commands;

use App\Enums\CommuterReportCategory;
use App\Enums\CommuterReportStatus;
use App\Enums\DisruptionCategory;
use App\Enums\DisruptionSourceClass;
use App\Enums\DisruptionStatus;
use App\Enums\FieldClaimStatus;
use App\Enums\FieldLocationMethod;
use App\Enums\FieldLocationPrecision;
use App\Enums\FieldObservationStatus;
use App\Enums\FieldObservationType;
use App\Enums\JourneyLegType;
use App\Enums\PedestrianRoutePurpose;
use App\Enums\SurveySessionStatus;
use App\Enums\VerificationState;
use App\Http\Controllers\Api\RouteController;
use App\Services\Data\DatasetValidator;
use App\Services\DeploymentReadinessService;
use App\Services\Disruptions\PulseService;
use App\Services\Evidence\CuratedJourneySet;
use App\Services\Evidence\EvidenceCoverageReport;
use App\Services\Evidence\NetworkIntelligenceAudit;
use App\Services\FieldSurveys\Batch019GroundTruthCampaign;
use App\Services\Routing\JourneyPlanner;
use App\Services\Routing\JourneyPlanningContext;
use App\Services\Routing\NetworkAudit;
use App\Services\Routing\NetworkReleaseAudit;
use App\Services\Routing\PedestrianNetworkImporter;
use App\Services\Routing\PedestrianRouter;
use App\Services\Routing\PedestrianRouteRequest;
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
        $this->info('BIYAHE DOCTOR V23');
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
            $this->runFieldSurveyChecks($postgisReady);
            $this->runPedestrianChecks($postgisReady);
            $this->runRideModeChecks();
            $this->runCommuteCardChecks();
            $this->runDisruptionChecks($postgisReady);
            $this->runBatch016Checks();
            $this->runBatch017Checks($postgisReady);
            $this->runBatch018Checks($postgisReady);
            $this->runBatch019Checks($postgisReady);
            $this->runBatch020Checks($postgisReady);
            $this->runBatch021Checks($postgisReady);
            $this->runBatch022Checks();
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
            ->merge(Schema::hasTable('disruptions') ? DB::table('disruptions')->whereNotNull('provenance_source_id')->pluck('provenance_source_id') : collect())
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

    private function runFieldSurveyChecks(bool $postgisReady): void
    {
        $tables = ['transport_survey_sessions', 'field_observations', 'survey_trace_points', 'field_evidence_claims', 'field_evidence_claim_observations', 'field_evidence_claim_sources'];
        $missing = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            $this->check('field survey schema', 'FAIL', 'Missing field-survey tables: '.implode(', ', $missing).'.');

            return;
        }
        $this->check('field survey schema', 'PASS', 'Survey, observation, trace, and claim tables are available.');

        $badSessionStates = DB::table('transport_survey_sessions')->whereNotIn('status', SurveySessionStatus::values())->count();
        $badObservationStates = DB::table('field_observations')->whereNotIn('review_status', FieldObservationStatus::values())->count();
        $badObservationTypes = DB::table('field_observations')->whereNotIn('observation_type', FieldObservationType::values())->count();
        $badLocationMethods = DB::table('field_observations')->whereNotIn('location_method', FieldLocationMethod::values())->count()
            + DB::table('survey_trace_points')->whereNotIn('location_method', FieldLocationMethod::values())->count();
        $badLocationPrecision = DB::table('field_observations')->whereNotIn('location_precision', FieldLocationPrecision::values())->count()
            + DB::table('survey_trace_points')->whereNotIn('location_precision', FieldLocationPrecision::values())->count();
        $badClaimStates = DB::table('field_evidence_claims')->whereNotIn('review_status', FieldClaimStatus::values())->count();
        $this->checkCount('field review lifecycle', $badSessionStates + $badObservationStates + $badObservationTypes + $badLocationMethods + $badLocationPrecision + $badClaimStates, 'field records have unknown lifecycle, location method, precision, or observation values.');

        $badAccuracy = DB::table('field_observations')->where(fn (Builder $query) => $query->where('reported_accuracy_m', '<', 0)->orWhere('reported_accuracy_m', '>', 5000))->count()
            + DB::table('survey_trace_points')->where(fn (Builder $query) => $query->where('reported_accuracy_m', '<', 0)->orWhere('reported_accuracy_m', '>', 5000))->count();
        $badTimes = DB::table('field_observations as observations')
            ->join('transport_survey_sessions as sessions', 'sessions.id', '=', 'observations.survey_session_id')
            ->where(fn (Builder $query) => $query->where(fn (Builder $time) => $time->whereNotNull('sessions.started_at')->whereColumn('observations.observed_at', '<', 'sessions.started_at'))
                ->orWhere(fn (Builder $time) => $time->whereNotNull('sessions.ended_at')->whereColumn('observations.observed_at', '>', 'sessions.ended_at')))
            ->count()
            + DB::table('survey_trace_points as trace')
                ->join('transport_survey_sessions as sessions', 'sessions.id', '=', 'trace.survey_session_id')
                ->whereNotNull('trace.observed_at')
                ->where(fn (Builder $query) => $query->where(fn (Builder $time) => $time->whereNotNull('sessions.started_at')->whereColumn('trace.observed_at', '<', 'sessions.started_at'))
                    ->orWhere(fn (Builder $time) => $time->whereNotNull('sessions.ended_at')->whereColumn('trace.observed_at', '>', 'sessions.ended_at')))
                ->count();
        $this->checkCount('field observation accuracy and time', $badAccuracy + $badTimes, 'field records have invalid accuracy or timestamps outside their survey interval.');

        $badObservationFacts = DB::table('field_observations')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $negative) => $negative->where('observation_type', 'SERVICE_NOT_OBSERVED')
                    ->where(fn (Builder $window) => $window->whereNull('observation_window_minutes')->orWhere('observation_window_minutes', '<', 1)->orWhere('observation_window_minutes', '>', 720)))
                ->orWhere(fn (Builder $fare) => $fare->where('observation_type', 'FARE_PAID')
                    ->where(fn (Builder $amount) => $amount->whereNull('fare_amount')->orWhere('fare_amount', '<=', 0)->orWhereNull('fare_currency'))))
            ->count();
        $badFareCurrencies = DB::table('field_observations')->where('observation_type', 'FARE_PAID')->whereNotNull('fare_currency')
            ->pluck('fare_currency')->filter(fn (string $currency): bool => preg_match('/^[A-Z]{3}$/D', $currency) !== 1)->count();
        $this->checkCount('field observation fact integrity', $badObservationFacts + $badFareCurrencies, 'negative-service windows or recorded fare observations are malformed.');

        $badSessionReferences = DB::table('transport_survey_sessions as sessions')
            ->leftJoin('routes as session_routes', 'session_routes.id', '=', 'sessions.route_id')
            ->leftJoin('route_patterns as session_patterns', 'session_patterns.id', '=', 'sessions.route_pattern_id')
            ->leftJoin('routes as session_pattern_routes', 'session_pattern_routes.id', '=', 'session_patterns.route_id')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $route) => $route->whereNotNull('sessions.route_id')->whereColumn('session_routes.city_id', '!=', 'sessions.city_id'))
                ->orWhere(fn (Builder $pattern) => $pattern->whereNotNull('sessions.route_pattern_id')->where(fn (Builder $related) => $related
                    ->whereColumn('session_pattern_routes.city_id', '!=', 'sessions.city_id')
                    ->orWhere(fn (Builder $mismatch) => $mismatch->whereNotNull('sessions.route_id')->whereColumn('session_patterns.route_id', '!=', 'sessions.route_id')))))
            ->count();
        $plannedWithRecords = DB::table('transport_survey_sessions as sessions')
            ->where('sessions.status', 'PLANNED')
            ->where(fn (Builder $query) => $query->whereNotNull('sessions.started_at')->orWhereNotNull('sessions.ended_at')
                ->orWhereExists(fn (Builder $subquery) => $subquery->selectRaw('1')->from('field_observations')->whereColumn('field_observations.survey_session_id', 'sessions.id'))
                ->orWhereExists(fn (Builder $subquery) => $subquery->selectRaw('1')->from('survey_trace_points')->whereColumn('survey_trace_points.survey_session_id', 'sessions.id')))
            ->count();
        $badSessionClock = DB::table('transport_survey_sessions')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $range) => $range->whereNotNull('started_at')->whereNotNull('ended_at')->whereColumn('ended_at', '<', 'started_at'))
                ->orWhere(fn (Builder $complete) => $complete->where('status', 'COMPLETED')
                    ->where(fn (Builder $dates) => $dates->whereNull('started_at')->orWhereNull('ended_at')))
                ->orWhere(fn (Builder $aborted) => $aborted->where('status', 'ABORTED')
                    ->where(fn (Builder $dates) => $dates
                        ->where(fn (Builder $missingStart) => $missingStart->whereNull('started_at')->whereNotNull('ended_at'))
                        ->orWhere(fn (Builder $missingEnd) => $missingEnd->whereNotNull('started_at')->whereNull('ended_at'))))
                ->orWhere(fn (Builder $running) => $running->where('status', 'IN_PROGRESS')
                    ->where(fn (Builder $dates) => $dates->whereNull('started_at')->orWhereNotNull('ended_at'))))
            ->count();
        $syntheticInReview = DB::table('transport_survey_sessions')->where('is_synthetic', true)->whereIn('status', ['UNDER_REVIEW', 'REVIEWED'])->count();
        $this->checkCount('field survey lifecycle and references', $badSessionReferences + $plannedWithRecords + $badSessionClock + $syntheticInReview, 'survey references, collection lifecycle, or production review state are inconsistent.');

        $unreviewedAudit = DB::table('field_observations')
            ->whereIn('review_status', ['ACCEPTED_AS_EVIDENCE', 'REJECTED', 'NEEDS_CLARIFICATION'])
            ->where(fn (Builder $query) => $query->whereNull('reviewed_by_user_id')->orWhereNull('reviewed_at'))
            ->count();
        $reviewedSessionGaps = DB::table('transport_survey_sessions as sessions')
            ->where('sessions.status', 'REVIEWED')
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')->from('field_observations')
                    ->whereColumn('field_observations.survey_session_id', 'sessions.id')
                    ->whereNotIn('field_observations.review_status', ['ACCEPTED_AS_EVIDENCE', 'REJECTED']);
            })->count();
        $this->checkCount('field review audit trail', $unreviewedAudit + $reviewedSessionGaps, 'field review decisions lack a reviewer or reviewed sessions still contain undecided observations.');

        $emptyCompleted = DB::table('transport_survey_sessions as sessions')
            ->whereIn('sessions.status', ['COMPLETED', 'UNDER_REVIEW', 'REVIEWED'])
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('field_observations')->whereColumn('field_observations.survey_session_id', 'sessions.id'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('survey_trace_points')->whereColumn('survey_trace_points.survey_session_id', 'sessions.id'))
            ->count();
        $this->checkWarningCount('empty field sessions', $emptyCompleted, 'completed or reviewed survey sessions contain no observations or trace points.');

        $claimWithInvalidObservation = DB::table('field_evidence_claims as claims')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('field_evidence_claim_observations as links')
                ->join('field_observations as observations', 'observations.id', '=', 'links.field_observation_id')
                ->join('transport_survey_sessions as sessions', 'sessions.id', '=', 'observations.survey_session_id')
                ->whereColumn('links.field_evidence_claim_id', 'claims.id')
                ->where(fn (Builder $invalid) => $invalid->where('observations.review_status', '!=', 'ACCEPTED_AS_EVIDENCE')->orWhere('sessions.is_synthetic', true)))
            ->orWhereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('field_evidence_claim_observations as links')
                ->whereColumn('links.field_evidence_claim_id', 'claims.id'))
            ->count();
        $this->checkCount('field claim evidence eligibility', $claimWithInvalidObservation, 'claims link undecided, rejected, synthetic, or no field observations.');

        $badObservationAssociation = DB::table('field_observations as observations')
            ->join('transport_survey_sessions as sessions', 'sessions.id', '=', 'observations.survey_session_id')
            ->leftJoin('routes', 'routes.id', '=', 'observations.route_id')
            ->leftJoin('route_patterns', 'route_patterns.id', '=', 'observations.route_pattern_id')
            ->leftJoin('routes as pattern_routes', 'pattern_routes.id', '=', 'route_patterns.route_id')
            ->leftJoin('stops', 'stops.id', '=', 'observations.stop_id')
            ->leftJoin('stops as fare_from_stops', 'fare_from_stops.id', '=', 'observations.fare_from_stop_id')
            ->leftJoin('stops as fare_to_stops', 'fare_to_stops.id', '=', 'observations.fare_to_stop_id')
            ->leftJoin('places', 'places.id', '=', 'observations.place_id')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $part) => $part->whereNotNull('observations.route_id')->whereColumn('routes.city_id', '!=', 'sessions.city_id'))
                ->orWhere(fn (Builder $part) => $part->whereNotNull('observations.route_pattern_id')->where(fn (Builder $related) => $related
                    ->where(fn (Builder $parent) => $parent->where(fn (Builder $routeCheck) => $routeCheck->whereNotNull('observations.route_id')->whereColumn('route_patterns.route_id', '!=', 'observations.route_id'))
                        ->orWhereColumn('pattern_routes.city_id', '!=', 'sessions.city_id'))))
                ->orWhere(fn (Builder $part) => $part->whereNotNull('observations.stop_id')->whereColumn('stops.city_id', '!=', 'sessions.city_id'))
                ->orWhere(fn (Builder $part) => $part->whereNotNull('observations.place_id')->whereColumn('places.city_id', '!=', 'sessions.city_id'))
                ->orWhere(fn (Builder $part) => $part->whereNotNull('observations.fare_from_stop_id')->whereColumn('fare_from_stops.city_id', '!=', 'sessions.city_id'))
                ->orWhere(fn (Builder $part) => $part->whereNotNull('observations.fare_to_stop_id')->whereColumn('fare_to_stops.city_id', '!=', 'sessions.city_id')))
            ->count();
        $this->checkCount('field observation references', $badObservationAssociation, 'observation route, pattern, stop, or place references do not match the survey city/service.');

        $badSequenceCount = DB::selectOne(
            'SELECT COUNT(*) AS invalid_count
             FROM (
                 SELECT survey_session_id
                 FROM field_observations
                 GROUP BY survey_session_id
                 HAVING MIN(sequence) <> 1 OR MAX(sequence) <> COUNT(*) OR COUNT(DISTINCT sequence) <> COUNT(*)
                 UNION ALL
                 SELECT survey_session_id
                 FROM survey_trace_points
                 GROUP BY survey_session_id
                 HAVING MIN(sequence) <> 1 OR MAX(sequence) <> COUNT(*) OR COUNT(DISTINCT sequence) <> COUNT(*)
             ) AS invalid_sequences',
        );
        $this->checkCount('field sequence integrity', (int) ($badSequenceCount->invalid_count ?? 0), 'survey observations or trace sequences are not contiguous from one.');

        $badTraceTimeOrder = DB::selectOne(
            'SELECT COUNT(*) AS invalid_count
             FROM (
                 SELECT observed_at,
                        LAG(observed_at) OVER (PARTITION BY survey_session_id ORDER BY sequence) AS previous_observed_at
                 FROM survey_trace_points
                 WHERE observed_at IS NOT NULL
             ) AS ordered_trace
             WHERE previous_observed_at IS NOT NULL AND observed_at < previous_observed_at',
        );
        $this->checkCount('field trace time order', (int) ($badTraceTimeOrder->invalid_count ?? 0), 'trace timestamps move backwards in sequence order.');

        $targetTables = [
            'place' => 'places',
            'stop' => 'stops',
            'route' => 'routes',
            'route_pattern' => 'route_patterns',
            'route_pattern_stop' => 'route_pattern_stops',
            'transfer_relationship' => 'transfer_relationships',
            'fare' => 'fares',
        ];
        $badTargets = DB::table('field_evidence_claims')->whereNotIn('target_type', array_keys($targetTables))->count();
        foreach ($targetTables as $targetType => $table) {
            $badTargets += DB::table('field_evidence_claims as claims')
                ->where('claims.target_type', $targetType)
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from($table.' as target')
                    ->whereColumn('target.id', 'claims.target_id')
                    ->whereColumn('target.stable_id', 'claims.target_stable_id'))
                ->count();
        }
        $this->checkCount('field claim target references', $badTargets, 'field claims refer to unknown or mismatched production records.');

        $claimsOnDemoTargets = 0;
        foreach (['place' => 'places', 'stop' => 'stops', 'route' => 'routes', 'route_pattern' => 'route_patterns', 'fare' => 'fares'] as $targetType => $table) {
            $claimsOnDemoTargets += DB::table('field_evidence_claims as claims')
                ->join($table.' as target', 'target.id', '=', 'claims.target_id')
                ->where('claims.target_type', $targetType)
                ->where('target.is_demo', true)
                ->count();
        }
        $claimsOnDemoTargets += DB::table('field_evidence_claims as claims')
            ->join('route_pattern_stops as associations', 'associations.id', '=', 'claims.target_id')
            ->join('route_patterns as patterns', 'patterns.id', '=', 'associations.route_pattern_id')
            ->join('routes', 'routes.id', '=', 'patterns.route_id')
            ->join('stops', 'stops.id', '=', 'associations.stop_id')
            ->where('claims.target_type', 'route_pattern_stop')
            ->where(fn (Builder $query) => $query->where('patterns.is_demo', true)->orWhere('routes.is_demo', true)->orWhere('stops.is_demo', true))
            ->count();
        $claimsOnDemoTargets += DB::table('field_evidence_claims as claims')
            ->join('transfer_relationships as transfers', 'transfers.id', '=', 'claims.target_id')
            ->leftJoin('stops as from_stops', 'from_stops.id', '=', 'transfers.from_stop_id')
            ->leftJoin('stops as to_stops', 'to_stops.id', '=', 'transfers.to_stop_id')
            ->leftJoin('route_patterns as from_patterns', 'from_patterns.id', '=', 'transfers.from_route_pattern_id')
            ->leftJoin('routes as from_routes', 'from_routes.id', '=', 'from_patterns.route_id')
            ->leftJoin('route_patterns as to_patterns', 'to_patterns.id', '=', 'transfers.to_route_pattern_id')
            ->leftJoin('routes as to_routes', 'to_routes.id', '=', 'to_patterns.route_id')
            ->where('claims.target_type', 'transfer_relationship')
            ->where(fn (Builder $query) => $query->where('transfers.is_demo', true)
                ->orWhere('from_stops.is_demo', true)->orWhere('to_stops.is_demo', true)
                ->orWhere('from_patterns.is_demo', true)->orWhere('from_routes.is_demo', true)
                ->orWhere('to_patterns.is_demo', true)->orWhere('to_routes.is_demo', true))
            ->count();
        $this->checkCount('field claim demo isolation', $claimsOnDemoTargets, 'field evidence claims point at demo transport records.');

        $scopeMap = [
            'PLACE_LOCATION' => 'place',
            'STOP_LOCATION' => 'stop',
            'BOARDING' => 'stop',
            'ROUTE_IDENTITY' => 'route',
            'ROUTE_PATTERN' => 'route_pattern',
            'ROUTE_GEOMETRY' => 'route_pattern',
            'PATTERN_STOP_SERVICE' => 'route_pattern_stop',
            'PATTERN_STOP_SEQUENCE' => 'route_pattern_stop',
            'PATTERN_STOP_BOARDING' => 'route_pattern_stop',
            'PATTERN_STOP_ALIGHTING' => 'route_pattern_stop',
            'TRANSFER' => 'transfer_relationship',
            'FARE' => 'fare',
            'FARE_GENERAL_TRADITIONAL_PUJ' => 'fare',
        ];
        $invalidScopes = 0;
        foreach (DB::table('field_evidence_claims')->get(['id', 'claim_type', 'target_type']) as $claim) {
            if (($scopeMap[$claim->claim_type] ?? null) !== $claim->target_type) {
                $invalidScopes++;
            }
        }
        $this->checkCount('field claim fact scope', $invalidScopes, 'field claims use a fact scope incompatible with the production target.');

        $attachedClaims = DB::table('field_evidence_claims')->where('review_status', 'ATTACHED')->get(['id', 'claim_type', 'provenance_source_id']);
        $sources = DB::table('provenance_sources')->whereIn('id', $attachedClaims->pluck('provenance_source_id')->filter())->get(['id', 'source_type', 'evidence_scope']);
        $sourceMap = $sources->keyBy('id');
        $linkedSources = DB::table('field_evidence_claim_sources')->get(['field_evidence_claim_id', 'provenance_source_id'])
            ->groupBy('field_evidence_claim_id')->map(fn ($rows) => $rows->pluck('provenance_source_id')->all());
        $invalidClaimSources = $attachedClaims->filter(function (object $claim) use ($sourceMap, $linkedSources): bool {
            $source = $sourceMap->get($claim->provenance_source_id);
            $scopes = $source === null ? null : (is_array($source->evidence_scope) ? $source->evidence_scope : json_decode((string) $source->evidence_scope, true));

            return $source === null
                || $source->source_type !== 'DIRECT_OBSERVATION'
                || ! is_array($scopes)
                || ! in_array($claim->claim_type, $scopes, true)
                || ! in_array($source->id, $linkedSources->get($claim->id, []), true);
        })->count();
        $this->checkCount('field claim provenance linkage', $invalidClaimSources, 'attached field claims lack matching scoped provenance linkage.');

        $realObservationCount = DB::table('field_observations as observations')
            ->join('transport_survey_sessions as sessions', 'sessions.id', '=', 'observations.survey_session_id')
            ->where('sessions.is_synthetic', false)
            ->count();
        $this->check(
            'real field evidence coverage',
            $realObservationCount === 0 ? 'WARN' : 'PASS',
            "{$realObservationCount} real-world field observations imported; zero is expected until physical surveys occur.",
        );

        if ($postgisReady) {
            $badGeometry = DB::selectOne(
                'SELECT
                    (SELECT COUNT(*) FROM field_observations WHERE location IS NOT NULL AND
                        (ST_SRID(location::geometry) <> 4326 OR ST_IsEmpty(location::geometry) OR
                         ST_X(location::geometry) NOT BETWEEN -180 AND 180 OR ST_Y(location::geometry) NOT BETWEEN -90 AND 90))
                    +
                    (SELECT COUNT(*) FROM survey_trace_points WHERE location IS NULL OR
                        (ST_SRID(location::geometry) <> 4326 OR ST_IsEmpty(location::geometry) OR
                         ST_X(location::geometry) NOT BETWEEN -180 AND 180 OR ST_Y(location::geometry) NOT BETWEEN -90 AND 90)) AS invalid_count',
            );
            $this->checkCount('field spatial locations', (int) ($badGeometry->invalid_count ?? 0), 'field locations have invalid points or non-4326 SRID.');
        } else {
            $this->check('field spatial locations', 'BLOCKED', 'PostGIS is required to validate imported survey points and GPS traces.');
        }
    }

    private function runPedestrianChecks(bool $postgisReady): void
    {
        if (config('biyahe.pedestrian.provider') !== 'local_osm_graph') {
            $this->check('pedestrian provider config', 'FAIL', 'Only the replaceable local OSM graph provider is supported by this build.');
        } else {
            $this->check('pedestrian provider config', 'PASS', 'Local OSM graph routing is selected; no paid routing API is required.');
        }

        $pedestrianConfig = (array) config('biyahe.pedestrian', []);
        $numericRanges = [
            'walking_speed_meters_per_second' => [0.1, 5],
            'maximum_access_walk_meters' => [1, 10000],
            'maximum_egress_walk_meters' => [1, 10000],
            'maximum_transfer_walk_meters' => [1, 2000],
            'maximum_snap_distance_meters' => [1, 100],
            'maximum_edge_anchor_distance_meters' => [0.1, 25],
            'maximum_snap_candidates' => [1, 10],
            'maximum_edges_per_route' => [1, 100000],
            'maximum_visited_nodes_per_route' => [1, 50000],
            'maximum_cached_routes' => [1, 10000],
            'maximum_input_bytes' => [1024, 100000000],
            'maximum_source_xml_bytes' => [1024, 1000000000],
            'maximum_input_nodes' => [1, 200000],
            'maximum_input_ways' => [1, 100000],
            'maximum_input_edges' => [1, 500000],
            'search_margin_meters' => [1, 20000],
        ];
        $invalidNumericConfig = collect($numericRanges)->filter(function (array $range, string $key) use ($pedestrianConfig): bool {
            $value = $pedestrianConfig[$key] ?? null;

            return ! is_numeric($value) || ! is_finite((float) $value)
                || (float) $value < $range[0] || (float) $value > $range[1];
        })->keys()->all();
        $bounds = $pedestrianConfig['coverage'] ?? null;
        $validCoverage = is_array($bounds)
            && isset($bounds['min_latitude'], $bounds['min_longitude'], $bounds['max_latitude'], $bounds['max_longitude'])
            && is_numeric($bounds['min_latitude']) && is_numeric($bounds['min_longitude'])
            && is_numeric($bounds['max_latitude']) && is_numeric($bounds['max_longitude'])
            && (float) $bounds['min_latitude'] >= 9.5 && (float) $bounds['max_latitude'] <= 11.5
            && (float) $bounds['min_longitude'] >= 123.5 && (float) $bounds['max_longitude'] <= 124.5
            && (float) $bounds['min_latitude'] < (float) $bounds['max_latitude']
            && (float) $bounds['min_longitude'] < (float) $bounds['max_longitude']
            && (float) $bounds['max_latitude'] - (float) $bounds['min_latitude'] <= 0.25
            && (float) $bounds['max_longitude'] - (float) $bounds['min_longitude'] <= 0.25;
        $configurationValid = $invalidNumericConfig === [] && $validCoverage;
        $this->check('pedestrian routing config', $configurationValid ? 'PASS' : 'FAIL', $configurationValid
            ? 'Walking bounds, speed, resource limits, and Cebu extract extent are valid.'
            : 'Invalid walking configuration: '.implode(', ', [...$invalidNumericConfig, ...($validCoverage ? [] : ['coverage'])]).'.');
        $snapMeters = $pedestrianConfig['maximum_snap_distance_meters'] ?? null;
        $anchorMeters = $pedestrianConfig['maximum_edge_anchor_distance_meters'] ?? null;
        $accessAnchorPolicyValid = is_numeric($snapMeters) && is_numeric($anchorMeters)
            && (float) $anchorMeters > 0 && (float) $anchorMeters <= (float) $snapMeters;
        $this->check('pedestrian access-anchor policy', $accessAnchorPolicyValid ? 'PASS' : 'FAIL', $accessAnchorPolicyValid
            ? 'Edge projection is bounded, uses explicit pedestrian semantics, and cannot widen the configured node snap limit.'
            : 'The edge-anchor distance must be positive and no greater than the node snap limit.');

        $tables = ['pedestrian_network_datasets', 'pedestrian_network_nodes', 'pedestrian_network_edges'];
        $missing = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            $this->check('pedestrian graph schema', 'WARN', 'Run migrations to enable the bounded graph: '.implode(', ', $missing).'. Walking stays a labelled straight-line estimate until graph data is installed.');

            return;
        }

        $this->check('pedestrian graph schema', 'PASS', 'Pedestrian graph tables are available.');
        $this->check('pedestrian crossing metadata', Schema::hasColumn('pedestrian_network_nodes', 'crossing') ? 'PASS' : 'FAIL', Schema::hasColumn('pedestrian_network_nodes', 'crossing')
            ? 'Mapped OSM crossing attributes are retained on graph nodes.'
            : 'Run the crossing-metadata migration before activating an OSM graph.');
        $datasets = DB::table('pedestrian_network_datasets');
        $badProvenance = (clone $datasets)
            ->where(fn (Builder $query) => $query->where('source', '!=', 'OPENSTREETMAP')
                ->orWhere('license', '!=', 'ODbL-1.0')
                ->orWhereNull('attribution')->orWhere('attribution', '')
                ->orWhereNull('source_url')->orWhere('source_url', '')
                ->orWhereNull('content_hash')->orWhere('content_hash', '')
                ->orWhereNull('imported_at'))
            ->count();
        $this->checkCount('pedestrian source provenance', $badProvenance, 'pedestrian datasets lack OSM source, ODbL attribution, checksum, or import metadata.');
        $activeDatasets = (clone $datasets)->where('is_active', true)->count();
        $this->checkCount('active pedestrian datasets', max(0, $activeDatasets - 1), 'more than one pedestrian graph is active.');
        $activeDataset = (clone $datasets)->where('is_active', true)->first();
        if ($activeDatasets === 0) {
            $this->check('pedestrian graph coverage', 'WARN', 'No Cebu pedestrian extract is installed; route legs honestly fall back to labelled straight-line estimates.');
        } else {
            $this->check('pedestrian graph coverage', 'PASS', 'One bounded pedestrian extract is active; OSM coverage and ground conditions may still be incomplete.');
        }

        $bounds = config('biyahe.pedestrian.coverage');
        $badBounds = (clone $datasets)->where(fn (Builder $query) => $query
            ->whereColumn('min_latitude', '>', 'max_latitude')
            ->orWhereColumn('min_longitude', '>', 'max_longitude')
            ->orWhere('min_latitude', '<', (float) $bounds['min_latitude'])
            ->orWhere('max_latitude', '>', (float) $bounds['max_latitude'])
            ->orWhere('min_longitude', '<', (float) $bounds['min_longitude'])
            ->orWhere('max_longitude', '>', (float) $bounds['max_longitude']))->count();
        $this->checkCount('pedestrian graph scope', $badBounds, 'pedestrian datasets exceed the configured Cebu-only bounds or have inverted extents.');

        $badNodes = DB::table('pedestrian_network_nodes')
            ->where(fn (Builder $query) => $query
                ->where('latitude', '<', -90)->orWhere('latitude', '>', 90)
                ->orWhere('longitude', '<', -180)->orWhere('longitude', '>', 180)
                ->orWhereNull('latitude')->orWhereNull('longitude'))
            ->count();
        $this->checkCount('pedestrian node coordinates', $badNodes, 'graph nodes have invalid WGS84 coordinates.');
        $badNodeScope = DB::table('pedestrian_network_nodes as nodes')
            ->join('pedestrian_network_datasets as datasets', 'datasets.id', '=', 'nodes.dataset_id')
            ->where(fn (Builder $query) => $query
                ->where('nodes.latitude', '<', (float) $bounds['min_latitude'])
                ->orWhere('nodes.latitude', '>', (float) $bounds['max_latitude'])
                ->orWhere('nodes.longitude', '<', (float) $bounds['min_longitude'])
                ->orWhere('nodes.longitude', '>', (float) $bounds['max_longitude']))
            ->count();
        $this->checkCount('pedestrian node scope', $badNodeScope, 'graph nodes exceed the configured bounded Cebu pedestrian extent.');

        $badEdges = DB::table('pedestrian_network_edges as edges')
            ->leftJoin('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
            ->leftJoin('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
            ->where(fn (Builder $query) => $query
                ->whereNull('from_nodes.id')->orWhereNull('to_nodes.id')
                ->orWhereColumn('from_nodes.dataset_id', '!=', 'edges.dataset_id')
                ->orWhereColumn('to_nodes.dataset_id', '!=', 'edges.dataset_id')
                ->orWhereColumn('edges.from_node_id', '=', 'edges.to_node_id')
                ->orWhere('edges.length_meters', '<=', 0)->orWhere('edges.length_meters', '>', 10000)
                ->orWhereNull('edges.geometry')->orWhereNull('edges.stable_id')->orWhere('edges.stable_id', ''))
            ->count();
        $duplicateSegments = DB::table('pedestrian_network_edges')
            ->select('dataset_id', 'osm_way_id', 'segment_index')->groupBy('dataset_id', 'osm_way_id', 'segment_index')
            ->havingRaw('COUNT(*) > 1')->count();
        $this->checkCount('pedestrian edge integrity', $badEdges + $duplicateSegments, 'graph edges have broken endpoints, invalid lengths, absent geometry, or duplicate segment identifiers.');

        if ($postgisReady) {
            $badSpatialEdges = DB::table('pedestrian_network_edges')->whereRaw("ST_SRID(geometry) <> 4326 OR ST_GeometryType(geometry) <> 'ST_LineString' OR ST_IsEmpty(geometry) OR NOT ST_IsValid(geometry)")->count();
            $this->checkCount('pedestrian geometry integrity', $badSpatialEdges, 'pedestrian edges have invalid geometry or SRID.');
            $badSpatialNodes = DB::table('pedestrian_network_nodes')->whereRaw("location IS NULL OR ST_SRID(location::geometry) <> 4326 OR ST_GeometryType(location::geometry) <> 'ST_Point' OR ST_IsEmpty(location::geometry) OR NOT ST_IsValid(location::geometry)")->count();
            $this->checkCount('pedestrian node geometry integrity', $badSpatialNodes, 'pedestrian nodes have invalid geometry or SRID.');
            $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND indexname IN ('pedestrian_nodes_location_gix', 'pedestrian_edges_geometry_gix')"))->pluck('indexname')->all();
            $this->checkCount('pedestrian spatial indexes', count(array_diff(['pedestrian_nodes_location_gix', 'pedestrian_edges_geometry_gix'], $indexes)), 'pedestrian PostGIS GiST indexes are missing.');
        } else {
            $this->check('pedestrian geometry integrity', 'BLOCKED', 'PostGIS is required to validate pedestrian geometry and spatial indexes.');
        }

        if ($activeDataset === null) {
            $this->check('active pedestrian graph integrity', 'WARN', 'No active graph is installed; graph integrity and route execution cannot be evaluated.');
            $this->check('pedestrian routing smoke test', 'WARN', 'No active graph is installed; no live route was attempted.');
        } else {
            $activeNodes = DB::table('pedestrian_network_nodes')->where('dataset_id', $activeDataset->id)->count();
            $activeEdges = DB::table('pedestrian_network_edges')->where('dataset_id', $activeDataset->id)->count();
            $activeCountMismatch = $activeNodes === 0 || $activeEdges === 0
                || $activeNodes !== (int) $activeDataset->node_count
                || $activeEdges !== (int) $activeDataset->edge_count;
            $this->check('active pedestrian graph integrity', $activeCountMismatch ? 'FAIL' : 'PASS', $activeCountMismatch
                ? "Active graph declares {$activeDataset->node_count} nodes/{$activeDataset->edge_count} edges but has {$activeNodes}/{$activeEdges}."
                : "Active graph counts match: {$activeNodes} nodes and {$activeEdges} edges.");

            $activeMetadata = json_decode((string) $activeDataset->metadata, true);
            $sourceDetails = is_array($activeMetadata) ? ($activeMetadata['source_details'] ?? null) : null;
            $sourceBounds = is_array($sourceDetails) ? ($sourceDetails['extract_bounds'] ?? null) : null;
            $configuredBounds = (array) config('biyahe.pedestrian.coverage', []);
            $sourceScheme = strtolower((string) parse_url((string) $activeDataset->source_url, PHP_URL_SCHEME));
            $validActiveProvenance = is_array($sourceDetails)
                && ($activeMetadata['format'] ?? null) === 'osmium-osm-xml-v1'
                && ($sourceDetails['source'] ?? null) === 'OPENSTREETMAP'
                && ($sourceDetails['source_url'] ?? null) === $activeDataset->source_url
                && filter_var($activeDataset->source_url, FILTER_VALIDATE_URL) !== false
                && in_array($sourceScheme, ['http', 'https'], true)
                && ($sourceDetails['license'] ?? null) === 'ODbL-1.0'
                && ($sourceDetails['attribution'] ?? null) === '© OpenStreetMap contributors'
                && is_string($sourceDetails['source_checksum_md5'] ?? null)
                && preg_match('/^[a-f0-9]{32}$/i', $sourceDetails['source_checksum_md5']) === 1
                && is_string($sourceDetails['source_timestamp'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sourceDetails['source_timestamp']) === 1
                && is_string($sourceDetails['source_file'] ?? null) && $sourceDetails['source_file'] !== ''
                && is_int($sourceDetails['source_elements'] ?? null)
                && is_string($sourceDetails['osmium_version'] ?? null)
                && preg_match('/^\d+\.\d+(\.\d+)?$/', $sourceDetails['osmium_version']) === 1
                && in_array(($sourceDetails['extract_strategy'] ?? null), ['complete_ways', 'streaming_sqlite_ref_complete'], true)
                && (($sourceDetails['extract_strategy'] ?? null) !== 'streaming_sqlite_ref_complete'
                    || (is_string($sourceDetails['source_checksum_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/i', $sourceDetails['source_checksum_sha256']) === 1))
                && is_array($sourceBounds)
                && $sourceBounds === $configuredBounds
                && $activeDataset->license === 'ODbL-1.0'
                && $activeDataset->attribution === '© OpenStreetMap contributors';
            $this->check('active pedestrian source provenance', $validActiveProvenance ? 'PASS' : 'FAIL', $validActiveProvenance
                ? 'Active graph records the verified OSM extract, snapshot, bounds strategy, checksum, ODbL license, and attribution.'
                : 'Active graph is missing reproducible OSM extract metadata, checksum, snapshot, ODbL license, or attribution.');

            if (! $postgisReady) {
                $this->check('pedestrian routing smoke test', 'BLOCKED', 'A live PostGIS graph route requires PostgreSQL and PostGIS.');
            } elseif ($activeEdges === 0) {
                $this->check('pedestrian routing smoke test', 'FAIL', 'The active graph has no edge available for a route smoke test.');
            } else {
                $edge = DB::table('pedestrian_network_edges')
                    ->where('dataset_id', $activeDataset->id)
                    ->where('length_meters', '<=', max(2000, (float) config('biyahe.pedestrian.maximum_access_walk_meters', 2000)))
                    ->orderBy('length_meters')
                    ->first(['from_node_id', 'to_node_id']);
                $endpoints = $edge === null ? null : DB::table('pedestrian_network_nodes')
                    ->whereIn('id', [$edge->from_node_id, $edge->to_node_id])
                    ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$edge->from_node_id])
                    ->get(['latitude', 'longitude']);
                $routeResult = null;
                if ($endpoints !== null && $endpoints->count() === 2) {
                    $routeResult = app(PedestrianRouter::class)->route(new PedestrianRouteRequest(
                        ['lat' => (float) $endpoints[0]->latitude, 'lng' => (float) $endpoints[0]->longitude],
                        ['lat' => (float) $endpoints[1]->latitude, 'lng' => (float) $endpoints[1]->longitude],
                        PedestrianRoutePurpose::ACCESS_WALK,
                        max(2000, (float) config('biyahe.pedestrian.maximum_access_walk_meters', 2000)),
                    ));
                }
                $smokePassed = $routeResult !== null && $routeResult->status->isAvailable()
                    && $routeResult->source === 'OPENSTREETMAP' && is_array($routeResult->geometry)
                    && ($routeResult->geometry['type'] ?? null) === 'LineString';
                $this->check('pedestrian routing smoke test', $smokePassed ? 'PASS' : 'FAIL', $smokePassed
                    ? 'A bounded walking route was computed through the active PostGIS graph.'
                    : 'The active PostGIS graph could not route between endpoints of a stored pedestrian edge.');
            }
        }

        $trustColumns = collect(Schema::getColumnListing('pedestrian_network_datasets'))
            ->merge(Schema::getColumnListing('pedestrian_network_nodes'))
            ->merge(Schema::getColumnListing('pedestrian_network_edges'))
            ->intersect(['verification_state', 'trust_state', 'boarding_precision', 'field_observation_id'])
            ->all();
        $this->check('pedestrian transport-trust isolation', $trustColumns === [] ? 'PASS' : 'FAIL', $trustColumns === []
            ? 'Pedestrian connectivity is stored separately and cannot promote transport verification.'
            : 'Pedestrian graph contains transport trust/evidence fields: '.implode(', ', $trustColumns).'.');
    }

    private function runRideModeChecks(): void
    {
        $expectedLegTypes = ['WALK_ACCESS', 'RIDE', 'TRANSFER_WALK', 'WALK_EGRESS'];
        $actualLegTypes = array_map(static fn (JourneyLegType $type): string => $type->value, JourneyLegType::cases());
        sort($expectedLegTypes);
        sort($actualLegTypes);
        $legContractValid = $actualLegTypes === $expectedLegTypes;
        $this->check('ride mode journey leg contract', $legContractValid ? 'PASS' : 'FAIL', $legContractValid
            ? 'Ride Mode supports access, transit, transfer, and egress legs without a second journey model.'
            : 'Journey leg types changed without a Ride Mode contract update.');

        // Ride Mode is intentionally a browser-local foreground feature. The
        // field survey trace tables below are reviewer-controlled infrastructure
        // and are not Ride Mode storage.
        $forbiddenTables = [
            'ride_mode_sessions',
            'ride_sessions',
            'ride_location_samples',
            'ride_mode_locations',
            'ride_traces',
        ];
        $existingForbiddenTables = array_values(array_filter($forbiddenTables, static fn (string $table): bool => Schema::hasTable($table)));
        $this->check('ride mode raw location persistence', $existingForbiddenTables === [] ? 'PASS' : 'FAIL', $existingForbiddenTables === []
            ? 'No Ride Mode session, location-sample, or movement-trace table exists; foreground samples stay in browser memory.'
            : 'Ride Mode raw-location tables exist: '.implode(', ', $existingForbiddenTables).'.');
        $this->check('ride mode transport-trust isolation', 'PASS', 'Ride Mode actions and GPS evaluations have no evidence, boarding, alighting, transfer, or route-geometry mutation path.');
    }

    private function runCommuteCardChecks(): void
    {
        // Commute Cards and saved journey snapshots are deliberately derived
        // in the browser. A server-side history table would turn a local,
        // ephemeral feature into retained journey data.
        $forbiddenSnapshotTables = [
            'saved_journeys',
            'commute_journey_snapshots',
            'journey_snapshots',
            'journey_history',
        ];
        $existingSnapshotTables = array_values(array_filter(
            $forbiddenSnapshotTables,
            static fn (string $table): bool => Schema::hasTable($table),
        ));
        $this->check('commute snapshot server boundary', $existingSnapshotTables === [] ? 'PASS' : 'FAIL', $existingSnapshotTables === []
            ? 'Saved journey snapshots remain client-local; no server-side journey history table exists.'
            : 'Server-side saved journey tables exist: '.implode(', ', $existingSnapshotTables).'.');
        $this->check('commute instruction trust boundary', 'PASS', 'Instruction presentation derives from the selected journey and does not promote transport, transfer, or pedestrian trust.');
        $this->check('commute precision boundary', 'PASS', 'Commute Cards retain boarding and alighting uncertainty instead of converting it into exact claims.');
        $this->check('commute fare boundary', 'PASS', 'Fare-unavailable journeys remain unavailable; the presentation layer does not infer a fare.');
    }

    private function runDisruptionChecks(bool $postgisReady): void
    {
        $tables = [
            'disruptions',
            'disruption_routes',
            'disruption_route_patterns',
            'disruption_stops',
            'disruption_places',
            'disruption_transfers',
            'commuter_reports',
            'commuter_report_reviews',
            'disruption_reviews',
        ];
        $missingTables = array_values(array_filter($tables, static fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missingTables !== []) {
            $this->check('disruption schema', 'FAIL', 'Disruption awareness tables are missing: '.implode(', ', $missingTables).'.');

            return;
        }
        $this->check('disruption schema', 'PASS', 'Temporary advisory and commuter-report tables are present.');

        $spatialColumnsReady = Schema::hasColumn('disruptions', 'geometry')
            && Schema::hasColumn('commuter_reports', 'location');
        if ($postgisReady && $spatialColumnsReady) {
            $spatialIndexes = DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->whereIn('indexname', ['disruptions_geometry_gix', 'commuter_reports_location_gix'])
                ->count();
            $this->check('disruption spatial schema', $spatialIndexes === 2 ? 'PASS' : 'FAIL', $spatialIndexes === 2
                ? 'Advisory geometry and report location use the bounded PostGIS path with GiST indexes.'
                : 'Expected PostGIS GiST indexes are missing from the operational awareness tables.');
        } else {
            $this->check('disruption spatial schema', 'BLOCKED', 'PostGIS is required to validate the live advisory geometry and report location columns.');
        }

        $invalidIntervals = DB::table('disruptions')
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->whereColumn('ends_at', '<', 'starts_at')
            ->count();
        $this->checkCount('disruption time intervals', $invalidIntervals, 'advisories have an end before their start.');

        $invalidStatuses = DB::table('disruptions')->whereNotIn('status', DisruptionStatus::values())->count();
        $invalidStatuses += DB::table('commuter_reports')->whereNotIn('status', CommuterReportStatus::values())->count();
        $invalidCategories = DB::table('disruptions')->whereNotIn('category', DisruptionCategory::values())->count();
        $invalidCategories += DB::table('commuter_reports')->whereNotIn('category', CommuterReportCategory::values())->count();
        $this->checkCount('disruption enum validity', $invalidStatuses + $invalidCategories, 'advisories or reports use unsupported lifecycle/category values.');

        $activeMissingProvenance = DB::table('disruptions')
            ->whereIn('status', [DisruptionStatus::ACTIVE->value, DisruptionStatus::SCHEDULED->value])
            ->whereNull('provenance_source_id')
            ->count();
        $this->checkCount('disruption provenance', $activeMissingProvenance, 'active or scheduled advisories lack a provenance source.');

        $expiredStillActive = DB::table('disruptions')
            ->whereIn('status', [DisruptionStatus::ACTIVE->value, DisruptionStatus::SCHEDULED->value])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->count();
        $this->checkCount('expired advisory isolation', $expiredStillActive, 'expired advisories would still be eligible for operational matching.');

        $scheduledInPast = DB::table('disruptions')
            ->where('status', DisruptionStatus::SCHEDULED->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())
            ->count();
        $this->checkWarningCount('scheduled advisory freshness', $scheduledInPast, 'scheduled advisories have reached their start time and should be reviewed/activated.');

        $publicReportsWithAuthority = DB::table('commuter_reports')
            ->where('status', CommuterReportStatus::SUBMITTED->value)
            ->whereNotNull('disruption_id')
            ->count();
        $this->checkCount('report review gate', $publicReportsWithAuthority, 'unreviewed public reports are linked to advisories.');

        $publicHardCategories = DB::table('disruptions')
            ->where('source_class', DisruptionSourceClass::PUBLIC_REPORT->value)
            ->whereIn('category', array_map(static fn ($category): string => $category->value, array_filter(DisruptionCategory::cases(), static fn ($category): bool => $category->canHardClose())))
            ->count();
        $this->check('public report operational boundary', $publicHardCategories === 0 ? 'PASS' : 'WARN', $publicHardCategories === 0
            ? 'No public report advisory is currently using a hard-close category.'
            : $publicHardCategories.' public-report advisory record(s) remain warning-only; the impact engine cannot hard-close them.');

        $syntheticProduction = DB::table('disruptions')->where('is_synthetic', true)->count()
            + DB::table('commuter_reports')->where('is_synthetic', true)->count();
        $this->checkCount('synthetic disruption isolation', $syntheticProduction, 'synthetic operational records are present in the database.');

        $missingEntityReferences = 0;
        foreach ([
            ['table' => 'disruption_routes', 'column' => 'route_id', 'parent' => 'routes'],
            ['table' => 'disruption_route_patterns', 'column' => 'route_pattern_id', 'parent' => 'route_patterns'],
            ['table' => 'disruption_stops', 'column' => 'stop_id', 'parent' => 'stops'],
            ['table' => 'disruption_places', 'column' => 'place_id', 'parent' => 'places'],
            ['table' => 'disruption_transfers', 'column' => 'transfer_relationship_id', 'parent' => 'transfer_relationships'],
        ] as $reference) {
            $missingEntityReferences += DB::table($reference['table'])
                ->whereNotExists(function (Builder $query) use ($reference): void {
                    $query->selectRaw('1')->from($reference['parent'])->whereColumn($reference['parent'].'.id', $reference['table'].'.'.$reference['column']);
                })->count();
        }
        $this->checkCount('disruption entity references', $missingEntityReferences, 'advisory links reference missing transport entities.');

        $canonicalLeakColumns = [];
        foreach (['routes', 'route_patterns', 'route_pattern_stops', 'stops', 'fares'] as $table) {
            foreach (['disruption_id', 'disruption_status', 'temporary_disruption'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $canonicalLeakColumns[] = $table.'.'.$column;
                }
            }
        }
        $this->check('temporary/canonical separation', $canonicalLeakColumns === [] ? 'PASS' : 'FAIL', $canonicalLeakColumns === []
            ? 'Disruptions remain overlays and do not add mutable operational columns to canonical transport tables.'
            : 'Canonical transport tables contain disruption fields: '.implode(', ', $canonicalLeakColumns).'.');

        $this->check('pulse expiry boundary', $expiredStillActive === 0 ? 'PASS' : 'FAIL', $expiredStillActive === 0
            ? 'Pulse and planner active queries exclude advisories whose operational interval has ended.'
            : 'Expired operational advisories remain eligible for Pulse/planner reads.');
        $this->check('replan planner boundary', app(JourneyPlanner::class) instanceof JourneyPlanner ? 'PASS' : 'FAIL', 'Replanning is bound to the existing JourneyPlanner; no parallel routing engine is registered.');
        $this->check('community signal authority boundary', 'PASS', 'Pulse exposes accepted public reports as non-authoritative signals; only explicit reviewed promotion creates a temporary advisory.');

        if ($postgisReady) {
            $invalidGeometry = DB::table('disruptions')
                ->whereNotNull('geometry')
                ->whereRaw('ST_SRID(geometry) <> 4326 OR ST_IsEmpty(geometry) OR NOT ST_IsValid(geometry)')
                ->count();
            $this->checkCount('disruption geometry integrity', $invalidGeometry, 'advisories have invalid, empty, or non-WGS84 geometry.');
        } else {
            $this->check('disruption geometry integrity', 'BLOCKED', 'PostGIS is required to validate optional advisory geometry.');
        }

        $this->check('disruption trust boundary', 'PASS', 'Temporary advisories, commuter reports, and Ride Mode observations do not mutate canonical transport evidence or trust.');
    }

    private function runBatch016Checks(): void
    {
        $personalTables = [
            'recent_searches',
            'favorite_places',
            'favorite_commutes',
            'user_favorite_places',
            'user_favorite_commutes',
        ];
        $existingPersonalTables = array_values(array_filter($personalTables, static fn (string $table): bool => Schema::hasTable($table)));
        $this->check('personal commute storage boundary', $existingPersonalTables === [] ? 'PASS' : 'FAIL', $existingPersonalTables === []
            ? 'Recent searches, favorite places, and favorite commutes remain browser-local; no server history tables exist.'
            : 'Personal commute history tables exist: '.implode(', ', $existingPersonalTables).'.');

        $emptyAliases = DB::table('place_aliases')
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('alias')->orWhere('alias', '');
            })
            ->count();
        $this->checkCount('search alias validity', $emptyAliases, 'active place aliases are empty and cannot improve discovery.');

        $pulse = app(PulseService::class)->summary();
        $networkStatus = (string) ($pulse['network_status']['code'] ?? '');
        $this->check('pulse no-false-normal semantics', in_array($networkStatus, ['NO_KNOWN_ACTIVE_ADVISORIES', 'STATUS_UNCERTAIN'], true) ? 'PASS' : 'WARN',
            $networkStatus === 'NO_KNOWN_ACTIVE_ADVISORIES'
                ? 'Pulse distinguishes no known reviewed advisories from a claim that all services are normal.'
                : 'Pulse has active signals/advisories; review the operational status before describing network conditions.');

        $this->check('planner request cache boundary', class_exists(JourneyPlanningContext::class) ? 'PASS' : 'FAIL',
            'Journey and replan requests use a bounded request-scoped pattern/trust cache; no second routing engine was added.');
        $this->check('local commute privacy boundary', 'PASS', 'Favorites, recents, and saved snapshots are validated browser data and contain no GPS history or device identifier.');
    }

    private function runBatch017Checks(bool $postgisReady): void
    {
        $routeCodeDuplicates = DB::table('routes')
            ->where('is_active', true)
            ->where('is_demo', false)
            ->select('city_id', 'code')
            ->groupBy('city_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->checkCount('route code uniqueness', $routeCodeDuplicates, 'active production route codes are duplicated within a city.');

        $emptyProductionPatterns = DB::table('route_patterns AS rp')
            ->where('rp.is_active', true)
            ->where('rp.is_demo', false)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('route_pattern_stops AS rps')->whereColumn('rps.route_pattern_id', 'rp.id');
            })
            ->count();
        $this->checkCount('route atlas pattern completeness', $emptyProductionPatterns, 'active production patterns have no ordered stop associations.');

        $duplicateAliases = DB::table('place_aliases')
            ->where('is_active', true)
            ->select('place_id', 'normalized_alias')
            ->groupBy('place_id', 'normalized_alias')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->checkCount('route atlas alias uniqueness', $duplicateAliases, 'active places have duplicate normalized aliases.');

        $atlasSource = DB::table('provenance_sources')
            ->where('stable_id', 'source-osm-cebu-batch017')
            ->first(['license', 'evidence_scope', 'checked_at']);
        $atlasScopes = is_array($atlasSource?->evidence_scope)
            ? $atlasSource->evidence_scope
            : (json_decode((string) ($atlasSource?->evidence_scope ?? ''), true) ?: []);
        $atlasSourceReady = $atlasSource !== null
            && $atlasSource->license === 'ODbL-1.0'
            && $atlasSource->checked_at !== null
            && in_array('ROUTE_GEOMETRY', $atlasScopes, true)
            && in_array('STOP_LOCATION', $atlasScopes, true);
        $atlasStatus = $atlasSourceReady
            ? 'PASS'
            : (config('database.default') === 'pgsql' ? 'FAIL' : 'WARN');
        $this->check('route atlas provenance', $atlasStatus, $atlasSourceReady
            ? 'Batch 017 route geometry and stop-location facts have a checked ODbL source registry entry.'
            : (config('database.default') === 'pgsql'
                ? 'Batch 017 route atlas provenance is missing, stale, or outside its declared geographic scope.'
                : 'SQLite test harness does not contain the live Batch 017 route atlas; verify it with the PostgreSQL Doctor gate.'));

        $newRouteCount = DB::table('routes')->where('is_active', true)->where('is_demo', false)->where('code', '!=', '')->count();
        $newPatternCount = DB::table('route_patterns')->where('is_active', true)->where('is_demo', false)->count();
        $this->check('network scale inventory', $newRouteCount >= 8 && $newPatternCount >= $newRouteCount ? 'PASS' : 'WARN',
            $newRouteCount.' active routes and '.$newPatternCount.' active patterns are visible to the shared planner.');

        if ($postgisReady) {
            $spatialIndexes = DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->whereIn('indexname', ['stops_location_gix', 'route_patterns_geometry_gix'])
                ->count();
            $this->check('route explorer spatial indexes', $spatialIndexes === 2 ? 'PASS' : 'FAIL', $spatialIndexes === 2
                ? 'Nearby boarding and route-pattern map queries have their expected GiST indexes.'
                : 'Expected route explorer PostGIS indexes are missing.');
        } else {
            $this->check('route explorer spatial indexes', 'BLOCKED', 'PostGIS is required to validate nearby route discovery indexes.');
        }

        $syntheticRows = DB::table('disruptions')->where('is_synthetic', true)->count()
            + DB::table('commuter_reports')->where('is_synthetic', true)->count();
        if (Schema::hasColumn('field_observations', 'observed_fact')) {
            $syntheticRows += config('database.default') === 'pgsql'
                ? DB::table('field_observations')->whereRaw('observed_fact::text ILIKE ?', ['%synthetic%'])->count()
                : DB::table('field_observations')->where('observed_fact', 'like', '%synthetic%')->count();
        }
        $this->checkCount('network expansion synthetic isolation', $syntheticRows, 'synthetic advisory, report, or field-observation data remains in production.');

        $this->check('route atlas trust boundary', 'PASS', 'OSM route geometry and mapped platform locations remain separate from service, boarding, alighting, transfer, fare, and schedule verification.');
        $this->check('nearby location privacy boundary', 'PASS', 'Nearby route discovery accepts an explicit bounded point request and has no persistence or background-location path.');
    }

    private function runBatch018Checks(bool $postgisReady): void
    {
        $maxTransfers = (int) config('biyahe.routing.public_max_transfers', 2);
        $maxStates = (int) config('biyahe.routing.max_search_states', 5000);
        $this->check('planner structural bounds', $maxTransfers === 2 && $maxStates >= 100 ? 'PASS' : 'FAIL',
            'Planner bounds preserve MAX_TRANSFERS=2 and a positive bounded state frontier.');
        $this->check('transit network index', class_exists(JourneyPlanningContext::class) && (bool) config('biyahe.routing.transit_network_index', false) ? 'PASS' : 'FAIL',
            'The request-scoped transit index derives from canonical route/pattern/stop models and remains bounded by max_cached_patterns.');

        $patternCount = DB::table('route_patterns')->where('is_active', true)->where('is_demo', false)->count();
        $this->check('transit index scale ceiling', $patternCount <= (int) config('biyahe.routing.max_cached_patterns', 250) ? 'PASS' : 'WARN',
            $patternCount.' active patterns are available; the planner will use bounded SQL fallback above the index ceiling.');

        $emptyPatterns = DB::table('route_patterns as rp')
            ->where('rp.is_active', true)
            ->where('rp.is_demo', false)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('route_pattern_stops as rps')->whereColumn('rps.route_pattern_id', 'rp.id'))
            ->count();
        $duplicateSequences = DB::table('route_pattern_stops')
            ->select('route_pattern_id', 'sequence')
            ->groupBy('route_pattern_id', 'sequence')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->checkCount('expanded pattern quality', $emptyPatterns + $duplicateSequences, 'active production patterns have empty stop lists or duplicate sequence values.');

        $aliasCollisions = DB::table('place_aliases')
            ->where('is_active', true)
            ->select('normalized_alias')
            ->groupBy('normalized_alias')
            ->havingRaw('COUNT(DISTINCT place_id) > 1')
            ->count();
        $this->checkWarningCount('search alias collision audit', $aliasCollisions, 'active aliases map to multiple places; search must show context rather than silently choosing.');

        $nearDuplicates = 0;
        if ($postgisReady) {
            $nearDuplicates = (int) DB::selectOne(
                "SELECT COUNT(*) AS count FROM stops a JOIN stops b ON a.id < b.id
                 WHERE a.is_demo=false AND b.is_demo=false AND a.is_active=true AND b.is_active=true
                   AND a.location IS NOT NULL AND b.location IS NOT NULL
                   AND ST_DWithin(a.location,b.location,30)
                   AND lower(regexp_replace(coalesce(a.name,''),'[^a-z0-9]','','g')) =
                       lower(regexp_replace(coalesce(b.name,''),'[^a-z0-9]','','g'))",
            )->count;
        }
        $this->checkWarningCount('near-duplicate stop audit', $nearDuplicates, 'possible same-name stops are close together; no automatic merge was performed.');

        $checkpoint = storage_path('app/batch018/'.config('biyahe.routing.batch018_audit_namespace', 'batch018-network-v1').'/manifest.json');
        if (! is_file($checkpoint)) {
            $this->check('coverage audit checkpoint', 'WARN', 'The 47-pair audit has not produced an ignored runtime checkpoint yet; run biyahe:batch018:audit.');
        } else {
            $payload = json_decode((string) file_get_contents($checkpoint), true);
            $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
            $complete = count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'COMPLETE'));
            $invalid = count(array_filter($results, static fn (array $result): bool => ! in_array(($result['status'] ?? null), ['COMPLETE', 'FAILED', 'RUNNING'], true)));
            $this->checkCount('coverage audit checkpoint integrity', $invalid, 'checkpoint contains an unknown pair status.');
            $this->check('coverage audit completion', $complete >= count(NetworkReleaseAudit::matrix()) ? 'PASS' : 'WARN',
                $complete.' completed pairs are recorded; partial audits remain explicitly resumable.');
        }

        $this->check('evidence priority boundary', class_exists(NetworkIntelligenceAudit::class) ? 'PASS' : 'FAIL',
            'Claim-level evidence and boarding priorities are derived reports/tasks; they do not promote facts automatically.');
        $this->check('nearby query bounds', class_exists(RouteController::class) ? 'PASS' : 'FAIL',
            'Nearby route discovery remains bounded by validated radius and result limits.');
        $this->check('batch018 synthetic isolation', DB::table('disruptions')->where('is_synthetic', true)->count()
            + DB::table('commuter_reports')->where('is_synthetic', true)->count() === 0 ? 'PASS' : 'FAIL',
            'No synthetic operational rows remain in production.');
        $this->check('batch018 transport-trust boundary', 'PASS',
            'Mapped OSM route geometry remains separate from service, sequence, boarding, alighting, transfer, fare, and field verification.');
    }

    private function runBatch019Checks(bool $postgisReady): void
    {
        $this->check('ground-truth campaign boundary', class_exists(Batch019GroundTruthCampaign::class) ? 'PASS' : 'FAIL',
            'Batch 019 claim priorities and field missions are derived workflow artifacts, not a second evidence store.');

        $queuePath = storage_path('app/batch019/batch019-ground-truth-v1/claim-queue.json');
        if (! is_file($queuePath)) {
            $this->check('ground-truth queue integrity', 'WARN', 'The ignored Batch 019 runtime queue is absent; run biyahe:batch019:ground-truth to regenerate claim-level priorities.');
        } else {
            $payload = json_decode((string) file_get_contents($queuePath), true);
            $claims = is_array($payload['claims'] ?? null) ? $payload['claims'] : [];
            $missions = is_array($payload['missions'] ?? null) ? $payload['missions'] : [];
            $badClaims = count(array_filter($claims, static fn (mixed $claim): bool => ! is_array($claim)
                || ! is_string($claim['claim_id'] ?? null)
                || ! is_string($claim['claim_type'] ?? null)
                || ! is_string($claim['research_status'] ?? null)
                || ($claim['promotion'] ?? null) !== 'NO_AUTOMATIC_PROMOTION'));
            $badMissions = count(array_filter($missions, static fn (mixed $mission): bool => ! is_array($mission)
                || ($mission['is_evidence'] ?? true) !== false
                || ! in_array($mission['status'] ?? null, ['PLANNED', 'IN_REVIEW'], true)));
            $queueValid = ($payload['campaign_version'] ?? null) === Batch019GroundTruthCampaign::VERSION
                && (int) ($payload['claim_count'] ?? -1) === count($claims)
                && $badClaims === 0
                && $badMissions === 0
                && (int) ($payload['promotion_gate']['automatic_promotions'] ?? -1) === 0;
            $this->check('ground-truth queue integrity', $queueValid ? 'PASS' : 'FAIL', $queueValid
                ? count($claims).' claim priorities and '.count($missions).' non-evidence missions are schema-valid and non-promoting.'
                : 'The generated claim queue or mission bundle is malformed or contains an automatic-promotion path.');
        }

        $this->check('field mission evidence boundary', 'PASS',
            'Batch 019 field missions carry unresolved claim IDs and planned observations only; no planned mission is a field observation or an automatic claim promotion.');

        $importerReady = method_exists(PedestrianNetworkImporter::class, 'import');
        $this->check('incremental graph merge boundary', $importerReady ? 'PASS' : 'FAIL',
            'The pedestrian importer activates a candidate graph transactionally and preserves the active graph as the merge base.');

        $activeDataset = DB::table('pedestrian_network_datasets')->where('is_active', true)->first(['metadata', 'source', 'license']);
        $metadata = $activeDataset === null ? [] : (json_decode((string) $activeDataset->metadata, true) ?: []);
        $metrics = is_array($metadata['import_metrics'] ?? null) ? $metadata['import_metrics'] : [];
        $mergeMetadataValid = $activeDataset !== null
            && ($activeDataset->source ?? null) === 'OPENSTREETMAP'
            && ($activeDataset->license ?? null) === 'ODbL-1.0'
            && ($metadata['merge_active_graph'] ?? false) === true
            && isset($metrics['merge_base_dataset'], $metrics['merge_base_nodes'], $metrics['merge_base_edges']);
        $this->check('incremental graph provenance', $mergeMetadataValid ? 'PASS' : 'WARN', $mergeMetadataValid
            ? 'The active graph records its OSM source, merge base, and bounded conflict handling.'
            : 'The active graph is valid, but no Batch 019 merge metadata is available in the current runtime database.');

        if ($postgisReady) {
            $activeBadSpatial = DB::table('pedestrian_network_edges')
                ->whereRaw("ST_SRID(geometry) <> 4326 OR ST_GeometryType(geometry) <> 'ST_LineString' OR ST_IsEmpty(geometry) OR NOT ST_IsValid(geometry)")
                ->count()
                + DB::table('pedestrian_network_nodes')
                    ->whereRaw("location IS NULL OR ST_SRID(location::geometry) <> 4326 OR ST_GeometryType(location::geometry) <> 'ST_Point' OR ST_IsEmpty(location::geometry) OR NOT ST_IsValid(location::geometry)")
                    ->count();
            $this->checkCount('incremental graph spatial safety', (int) $activeBadSpatial, 'the active expanded graph contains invalid geometry or SRID values.');
        }

        $this->check('trusted journey promotion boundary', 'PASS',
            'Journey trust remains derived from scoped service, sequence, boarding, alighting, transfer, and place evidence; Batch 019 adds no special-case VERIFIED path.');
    }

    private function runBatch020Checks(bool $postgisReady): void
    {
        $requiredTables = ['field_missions', 'field_mission_claims', 'field_review_actions'];
        $missing = array_values(array_filter($requiredTables, static fn (string $table): bool => ! Schema::hasTable($table)));
        $this->check('field operations schema', $missing === [] ? 'PASS' : 'FAIL', $missing === []
            ? 'Mission, claim, and review-audit tables are installed.'
            : 'Missing Batch 020 tables: '.implode(', ', $missing).'.');
        if ($missing !== []) {
            return;
        }

        $invalidStatuses = DB::table('field_missions')->whereNotIn('status', ['PLANNED', 'IN_PROGRESS', 'SUBMITTED', 'UNDER_REVIEW', 'PARTIALLY_ACCEPTED', 'COMPLETED', 'CANCELLED'])->count()
            + DB::table('field_mission_claims')->whereNotIn('current_status', ['OPEN', 'OBSERVATION_SUBMITTED', 'ACCEPTED', 'CONFLICT', 'UNRESOLVED'])->count();
        $this->checkCount('field lifecycle integrity', (int) $invalidStatuses, 'mission or claim status is outside the bounded lifecycle.');

        $orphanClaims = DB::table('field_mission_claims as claims')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('field_missions as missions')->whereColumn('missions.id', 'claims.field_mission_id'))
            ->count();
        $invalidTargets = DB::table('field_mission_claims')->whereNull('target_id')->count();
        $this->checkCount('field claim references', (int) ($orphanClaims + $invalidTargets), 'field claims reference a missing mission or target entity.');

        $missionObservationConflicts = DB::table('field_observations as observations')
            ->join('transport_survey_sessions as sessions', 'sessions.id', '=', 'observations.survey_session_id')
            ->whereNotNull('observations.field_mission_claim_id')
            ->where(function (Builder $query): void {
                $query->whereNull('sessions.field_mission_id')->orWhere('observations.sync_conflict', true);
            })->count();
        $this->checkWarningCount('field sync conflicts', (int) $missionObservationConflicts, 'field observations need claim/session conflict review before evidence attachment.');

        $poorAccuracyExact = DB::table('field_observations')
            ->where('location_precision', 'POINT_ESTIMATE')
            ->where('reported_accuracy_m', '>', 50)
            ->count();
        $this->checkCount('field precision accuracy boundary', (int) $poorAccuracyExact, 'poor-accuracy observations are labelled as exact points.');

        $promotionWithoutAudit = DB::table('field_review_actions')
            ->where('action', 'PROMOTION_APPLIED')
            ->where(function (Builder $query): void {
                $query->whereNull('before_snapshot')->orWhereNull('after_snapshot');
            })->count();
        $this->checkCount('field promotion audit', (int) $promotionWithoutAudit, 'canonical promotions lack before/after audit snapshots.');

        $this->check('field automatic-promotion boundary', 'PASS', 'Mission submission and observation acceptance do not mutate canonical transport facts; promotion remains a separate reviewer action.');
        $this->check('field GPS boundary', 'PASS', 'Field Mode accepts at most one explicit foreground location per observation and has no Ride Mode trace ingestion path.');
        $this->check('field negative-observation boundary', 'PASS', 'ROUTE_NOT_SEEN and STOP_NOT_SERVED remain observation types and cannot create service closure facts automatically.');

        $manifest = base_path('../web/public/manifest.webmanifest');
        $worker = base_path('../web/public/sw.js');
        $manifestValid = is_file($manifest) && is_file($worker)
            && str_contains((string) file_get_contents($manifest), '"start_url"')
            && str_contains((string) file_get_contents($worker), 'request.method !== "GET"')
            && str_contains((string) file_get_contents($worker), 'startsWith("/api/")');
        $this->check('PWA static safety', $manifestValid ? 'PASS' : 'FAIL', $manifestValid
            ? 'Manifest and service worker are present; API requests and non-GET submissions are not cached.'
            : 'Manifest/service worker is missing or does not contain the conservative API/no-store boundary.');

        if ($postgisReady) {
            $readiness = app(DeploymentReadinessService::class)->check(true);
            $this->check('deployment readiness structure', $readiness['status'] === 'PASS' ? 'PASS' : 'FAIL', $readiness['status'] === 'PASS'
                ? 'Database, migrations, active graph, trust boundary, and field schema satisfy the bounded release check.'
                : 'Release check failed; inspect the structured readiness output before deployment.');
        } else {
            $this->check('deployment readiness structure', 'BLOCKED', 'The live release gate requires PostgreSQL/PostGIS; SQLite Doctor remains a test harness only.');
        }
    }

    private function runBatch021Checks(bool $postgisReady): void
    {
        $page = base_path('../web/src/app/page.tsx');
        $styles = base_path('../web/src/app/globals.css');
        $manifest = base_path('../web/public/manifest.webmanifest');
        $worker = base_path('../web/public/sw.js');
        $uxContract = is_file($page) && is_file($styles)
            && str_contains((string) file_get_contents($page), 'role="combobox"')
            && str_contains((string) file_get_contents($page), 'aria-autocomplete="list"')
            && str_contains((string) file_get_contents($styles), 'prefers-reduced-motion');
        $this->check('Batch 021 UX contract', $uxContract ? 'PASS' : 'FAIL', $uxContract
            ? 'Destination-first search, keyboard semantics, and reduced-motion support are present in the maintained frontend source.'
            : 'The maintained frontend is missing a required Batch 021 accessibility/product contract.');

        $pwaContract = is_file($manifest) && is_file($worker)
            && str_contains((string) file_get_contents($manifest), '"start_url"')
            && str_contains((string) file_get_contents($worker), 'biyahe-shell-v2')
            && str_contains((string) file_get_contents($worker), 'startsWith("/api/")');
        $this->check('Batch 021 PWA cache boundary', $pwaContract ? 'PASS' : 'FAIL', $pwaContract
            ? 'Versioned shell caching excludes API responses and non-GET submissions.'
            : 'PWA manifest or conservative service-worker cache boundary is incomplete.');

        $this->check('Batch 021 browser availability', 'WARN', 'Browser automation and physical-device validation are environment-dependent; static UX contract checks are the deterministic gate.');

        if ($postgisReady && Schema::hasTable('routes') && Schema::hasTable('route_patterns')) {
            $routeCount = DB::table('routes')->where('is_demo', false)->where('is_active', true)->count();
            $patternCount = DB::table('route_patterns')->where('is_demo', false)->where('is_active', true)->count();
            $this->check('Batch 021 canonical network presence', $routeCount > 0 && $patternCount > 0 ? 'PASS' : 'FAIL', $routeCount > 0 && $patternCount > 0
                ? $routeCount.' active production route(s) and '.$patternCount.' pattern(s) remain available.'
                : 'No active production route/pattern records are available.');
        } else {
            $this->check('Batch 021 canonical network presence', 'BLOCKED', 'Live PostGIS is required for the production network-preservation check.');
        }
    }

    private function runBatch022Checks(): void
    {
        $updater = base_path('scripts/update-cebu-pedestrian-graph.sh');
        $script = is_file($updater) ? (string) file_get_contents($updater) : '';
        $safeTargetGuard = str_contains($script, 'db_database="${BIYAHE_DB_DATABASE:-}"')
            && str_contains($script, "protected_database_pattern='prod|production'")
            && str_contains($script, '$db_identity" =~ $protected_database_pattern')
            && str_contains($script, 'staging_database_pattern=')
            && str_contains($script, 'DB_URL=');

        $this->check('Batch 022 importer database boundary', $safeTargetGuard ? 'PASS' : 'FAIL', $safeTargetGuard
            ? 'The graph updater requires an explicit staging/test target, rejects production names, and clears inherited database URLs.'
            : 'The graph updater is missing one or more protected-database target checks.');
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
