<?php

namespace App\Console\Commands;

use App\Services\Routing\NetworkReleaseAudit;
use Illuminate\Console\Command;

final class BiyaheBatch016Audit extends Command
{
    protected $signature = 'biyahe:batch016:audit {--pairs=12 : Number of explicit release-matrix pairs (up to the Batch 017 bounded matrix)} {--json : Emit machine-readable JSON}';

    protected $description = 'Run the bounded Cebu network inventory, corridor, journey-quality, and bottleneck audit.';

    public function handle(NetworkReleaseAudit $audit): int
    {
        $report = $audit->audit((int) $this->option('pairs'));
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $production = $report['inventory']['production'];
        $matrix = $report['matrix']['metrics'];
        $this->line('BIYAHE BATCH 016 NETWORK AUDIT');
        $this->line('production_places: '.$production['places']);
        $this->line('production_aliases: '.$production['aliases']);
        $this->line('production_stops: '.$production['stops']);
        $this->line('production_routes: '.$production['routes']);
        $this->line('production_patterns: '.$production['patterns']);
        $this->line('production_pattern_stops: '.$production['pattern_stops']);
        $this->line('production_transfers: '.$production['transfers']);
        $this->line('production_fares: '.$production['fares']);
        $this->line('matrix: '.json_encode($matrix));
        foreach ($report['corridors'] as $corridor) {
            $this->line('corridor: '.$corridor['label'].'='.$corridor['status'].' ('.$corridor['reason'].')');
        }
        $this->line('top_remaining_gaps: '.json_encode($report['matrix']['top_remaining_gaps']));
        $this->line('bottlenecks: '.json_encode($report['bottlenecks']));
        $this->line('elapsed_milliseconds: '.$report['performance']['elapsed_milliseconds']);
        $this->line('planning_context: '.json_encode($report['performance']['planning_context']));

        return self::SUCCESS;
    }
}
