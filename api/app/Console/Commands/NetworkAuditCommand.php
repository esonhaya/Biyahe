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
        foreach (['pairs_examined', 'routable', 'direct', 'one_transfer', 'no_known_journey', 'coverage_gap_pairs'] as $metric) {
            $this->line($metric.': '.$metrics[$metric]);
        }
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
