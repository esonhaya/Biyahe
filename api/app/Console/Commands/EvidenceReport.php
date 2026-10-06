<?php

namespace App\Console\Commands;

use App\Services\Evidence\EvidenceCoverageReport;
use App\Services\Evidence\EvidenceJourneyPlanCache;
use App\Services\Evidence\EvidencePriorityEngine;
use App\Services\Routing\PedestrianRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class EvidenceReport extends Command
{
    protected $signature = 'biyahe:evidence:report {--json : Emit machine-readable JSON} {--limit= : Maximum priority items} {--pairs= : Maximum origin/destination pairs for priority analysis} {--max-transfers= : Maximum transfers for priority analysis} {--skip-curated : Skip curated journey planning}';

    protected $description = 'Report claim-level evidence coverage and commuter-impact verification priorities.';

    public function handle(
        EvidenceCoverageReport $coverage,
        EvidencePriorityEngine $priority,
        EvidenceJourneyPlanCache $planCache,
        PedestrianRouter $pedestrianRouter,
    ): int {
        $connection = DB::connection();
        $wasLoggingQueries = $connection->logging();
        if (! $wasLoggingQueries) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();
        }
        $queriesBefore = count($connection->getQueryLog());
        $startedAt = hrtime(true);

        try {
            $report = $coverage->report(! $this->option('skip-curated'), true, $planCache);
            $report['priority'] = $priority->analyze(
                $this->option('limit') === null ? null : (int) $this->option('limit'),
                $this->option('pairs') === null ? null : (int) $this->option('pairs'),
                $this->option('max-transfers') === null ? null : (int) $this->option('max-transfers'),
                $planCache,
            );
        } finally {
            $elapsedMilliseconds = round((hrtime(true) - $startedAt) / 1_000_000, 2);
            $databaseQueries = count($connection->getQueryLog()) - $queriesBefore;
            if (! $wasLoggingQueries) {
                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }
        }

        $report['performance'] = [
            'elapsed_milliseconds' => $elapsedMilliseconds,
            'database_queries' => $databaseQueries,
            ...$planCache->metrics(),
        ];
        $report['pedestrian_routing'] = $pedestrianRouter->metrics();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('BIYAHE EVIDENCE COVERAGE');
        foreach ([
            'routes_total',
            'routes_verified',
            'patterns_total',
            'patterns_verified',
            'pattern_stops_total',
            'pattern_stops_service_verified',
            'pattern_stops_sequence_verified',
            'pattern_stops_boarding_verified',
            'pattern_stops_alighting_verified',
            'boarding_points_total',
            'boarding_points_exact',
            'boarding_points_area',
            'boarding_points_approximate',
            'boarding_points_unknown',
            'transfers_total',
            'transfers_verified',
            'transfers_unverified',
            'transfers_rejected',
            'fare_rules_current',
            'fare_rules_stale',
            'fare_rules_retired',
            'fare_rules_current_unverified',
            'fare_rules_missing_scope',
            'curated_journeys_total',
            'curated_journeys_verified',
            'curated_journeys_mixed',
            'curated_journeys_unverified',
            'verified_journey_percent',
        ] as $metric) {
            $this->line($metric.': '.$report[$metric]);
        }
        $this->line('priority_pairs_examined: '.$report['priority']['pairs_examined']);
        $this->line('priority_journeys_examined: '.$report['priority']['journeys_examined']);
        $this->line('report_elapsed_milliseconds: '.$report['performance']['elapsed_milliseconds']);
        $this->line('report_database_queries: '.$report['performance']['database_queries']);
        $this->line('planner_runs: '.$report['performance']['planner_runs']);
        $this->line('plan_cache_hits: '.$report['performance']['result_cache_hits']);
        $this->line('cached_pattern_models: '.$report['performance']['cached_patterns']);
        $this->line('pedestrian_candidates: '.$report['pedestrian_routing']['candidates']);
        $this->line('pedestrian_routes_computed: '.$report['pedestrian_routing']['routes_computed']);
        $this->line('pedestrian_cache_hits: '.$report['pedestrian_routing']['cache_hits']);
        $this->line('pedestrian_routing_milliseconds: '.$report['pedestrian_routing']['routing_time_milliseconds']);
        $this->line('priority_items: '.count($report['priority']['items']));
        foreach ($report['priority']['items'] as $item) {
            $this->line(sprintf(
                '%d. %s %s score=%s journeys=%d pairs=%d reason=%s',
                $item['rank'],
                $item['type'],
                $item['stable_id'] ?? ('#'.$item['record_id']),
                $item['score'],
                $item['affected_journeys'],
                count($item['affected_od_pairs']),
                $item['reason'],
            ));
        }

        return self::SUCCESS;
    }
}
