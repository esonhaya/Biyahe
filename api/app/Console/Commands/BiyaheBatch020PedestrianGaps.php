<?php

namespace App\Console\Commands;

use App\Services\Routing\PedestrianGapAnalysis;
use Illuminate\Console\Command;

final class BiyaheBatch020PedestrianGaps extends Command
{
    protected $signature = 'biyahe:batch020:pedestrian-gaps {--json : Emit bounded JSON output}';

    protected $description = 'Classify production stop/place access gaps against the active PostGIS graph without changing coordinates.';

    public function handle(PedestrianGapAnalysis $analysis): int
    {
        try {
            $result = $analysis->analyze();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('dataset='.$result['dataset']['stable_id']);
            $this->line('stops='.$result['stops']['total'].' connectable='.$result['stops']['connectable'].' unconnectable='.$result['stops']['unconnectable']);
            $this->line('places='.$result['places']['total'].' connectable='.$result['places']['connectable'].' unconnectable='.$result['places']['unconnectable']);
            $this->line('systemic_clusters='.count($result['systemic_clusters']));
            $this->line('coordinates_moved=0');
        }

        return self::SUCCESS;
    }
}
