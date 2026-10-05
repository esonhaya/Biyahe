<?php

namespace App\Console\Commands;

use App\Services\Routing\NetworkAudit;
use Illuminate\Console\Command;

class NetworkAuditCommand extends Command
{
    protected $signature = 'biyahe:network:audit {--places= : Maximum known places to inspect} {--pairs= : Maximum origin/destination pairs}';

    protected $description = 'Run a bounded developer audit over known Cebu place pairs.';

    public function handle(NetworkAudit $audit): int
    {
        $metrics = $audit->audit(
            maxPlaces: $this->option('places') === null ? null : (int) $this->option('places'),
            maxPairs: $this->option('pairs') === null ? null : (int) $this->option('pairs'),
        );

        $this->line('BIYAHE NETWORK AUDIT');
        foreach ([
            'pairs_examined',
            'routable',
            'direct',
            'one_transfer',
            'two_transfer',
            'no_known_journey',
            'coverage_gap_pairs',
            'average_transfers',
            'median_transfers',
            'can_i_ride_checked',
            'can_i_ride_yes',
            'can_i_ride_no',
            'can_i_ride_direction_dependent',
            'can_i_ride_unknown',
            'walking_bound_violations',
            'loops',
            'duplicate_journeys',
            'direction_violations',
            'transfer_cap_violations',
            'stale_leakage',
            'trust_leakage',
            'planner_errors',
            'can_i_ride_integrity_errors',
        ] as $metric) {
            $this->line($metric.': '.$metrics[$metric]);
        }
        $this->line('trust_distribution: '.json_encode($metrics['trust_distribution']));
        $this->line('warnings: '.count($metrics['warnings']));
        $this->line('errors: '.count($metrics['errors']));
        foreach ($metrics['warnings'] as $warning) {
            $this->warn($warning);
        }
        foreach ($metrics['errors'] as $error) {
            $this->error($error);
        }

        return $metrics['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
