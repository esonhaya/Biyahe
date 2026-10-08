<?php

namespace App\Console\Commands;

use App\Services\Routing\PedestrianNetworkImporter;
use Illuminate\Console\Command;
use Throwable;

final class ImportOfflinePedestrianGraph extends Command
{
    protected $signature = 'biyahe:pedestrian:import-offline {file : Completed SQLite graph artifact} {--source-path= : Verified local source PBF} {--staging-database= : Exact configured isolated staging/test database name}';

    protected $description = 'Stream a validated offline graph into an isolated database as an inactive dataset.';

    public function handle(PedestrianNetworkImporter $importer): int
    {
        try {
            $result = $importer->importOfflineSqlite(
                (string) $this->argument('file'),
                (string) $this->option('source-path'),
                (string) $this->option('staging-database'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line('dataset: '.$result->stableId);
        $this->line('nodes: '.$result->nodes);
        $this->line('edges: '.$result->edges);
        $this->line('idempotent_reuse: '.($result->alreadyImported ? 'yes' : 'no'));
        $this->line('activation: NOT_ACTIVATED');
        $this->info('Offline graph was staged transactionally; no transport or field records were changed.');

        return self::SUCCESS;
    }
}
