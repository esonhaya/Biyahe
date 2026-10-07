<?php

namespace App\Console\Commands;

use App\Services\Routing\NetworkReleaseAudit;
use Illuminate\Console\Command;

final class BiyaheBatch017Audit extends Command
{
    protected $signature = 'biyahe:batch017:audit {--pairs=36 : Number of explicit bounded release-matrix pairs} {--json : Emit machine-readable JSON}';

    protected $description = 'Run the expanded Metro Cebu route-atlas inventory and bounded journey coverage audit.';

    public function handle(NetworkReleaseAudit $audit): int
    {
        $report = $audit->audit((int) $this->option('pairs'));
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $production = $report['inventory']['production'];
        $matrix = $report['matrix']['metrics'];
        $this->line('BIYAHE BATCH 017 METRO CEBU AUDIT');
        foreach (['places', 'aliases', 'stops', 'routes', 'patterns', 'pattern_stops', 'transfers', 'fares'] as $key) {
            $this->line('production_'.$key.': '.($production[$key] ?? 0));
        }
        $this->line('matrix: '.json_encode($matrix));
        $this->line('top_remaining_gaps: '.json_encode($report['matrix']['top_remaining_gaps']));
        $this->line('bottlenecks: '.json_encode($report['bottlenecks']));
        $this->line('elapsed_milliseconds: '.$report['performance']['elapsed_milliseconds']);
        $this->line('planning_context: '.json_encode($report['performance']['planning_context']));

        return self::SUCCESS;
    }
}
