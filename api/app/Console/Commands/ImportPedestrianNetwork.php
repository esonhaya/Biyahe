<?php

namespace App\Console\Commands;

use App\Services\Routing\PedestrianNetworkImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class ImportPedestrianNetwork extends Command
{
    protected $signature = 'biyahe:pedestrian:import {file : Bounded pedestrian JSON extract} {--source-url= : Source/extract URL recorded for provenance}';

    protected $description = 'Validate and transactionally import a bounded Cebu OpenStreetMap pedestrian extract.';

    public function handle(PedestrianNetworkImporter $importer): int
    {
        try {
            $result = $importer->import((string) $this->argument('file'), (string) $this->option('source-url'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Pedestrian-network import rolled back. Review the server log for the internal error.');

            return self::FAILURE;
        }

        $this->line('dataset: '.$result->stableId);
        $this->line('nodes: '.$result->nodes);
        $this->line('edges: '.$result->edges);
        $this->line('source_elements: '.$result->sourceElements);
        $this->line('candidate_elements: '.$result->candidateElements);
        $this->line('rejected_elements: '.$result->rejectedElements);
        $this->line('restricted_elements: '.$result->restrictedElements);
        $this->line('import_runtime_milliseconds: '.$result->runtimeMilliseconds);
        $this->line('idempotent_reuse: '.($result->alreadyImported ? 'yes' : 'no'));
        $this->info('OpenStreetMap pedestrian graph imported with ODbL attribution; this does not verify transit facts.');

        return self::SUCCESS;
    }
}
