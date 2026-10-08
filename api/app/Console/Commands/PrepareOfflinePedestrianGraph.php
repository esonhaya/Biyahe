<?php

namespace App\Console\Commands;

use App\Services\Routing\OfflinePedestrianGraphStager;
use Illuminate\Console\Command;
use Throwable;

final class PrepareOfflinePedestrianGraph extends Command
{
    protected $signature = 'biyahe:pedestrian:stage-offline {input : Complete OSM XML path or - for a producer stream} {--output= : New SQLite graph artifact path} {--source-path= : Verified local source PBF} {--source-url= : Source extract URL} {--source-sha256= : Expected local PBF SHA-256} {--source-timestamp= : OSM snapshot timestamp when supplied} {--osmium-version= : Version of the PBF-to-XML producer} {--resume : Resume a matching .building SQLite checkpoint} {--stop-after-source : Keep a source checkpoint without publishing the graph} {--await-producer-exit : Require an explicit clean producer-exit confirmation before graph generation} {--producer-exit-confirmed : Confirm the producer exited successfully for a pending source checkpoint}';

    protected $description = 'Create a bounded, resumable SQLite pedestrian graph artifact without PostgreSQL.';

    public function handle(OfflinePedestrianGraphStager $stager): int
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $result = $stager->build(
                (string) $this->argument('input'),
                (string) $this->option('output'),
                (string) $this->option('source-path'),
                [
                    'url' => (string) $this->option('source-url'),
                    'sha256' => (string) $this->option('source-sha256'),
                    'timestamp' => $this->option('source-timestamp') ?: null,
                    'osmium_version' => (string) $this->option('osmium-version'),
                ],
                (bool) $this->option('resume'),
                (bool) $this->option('stop-after-source'),
                (bool) $this->option('await-producer-exit'),
                (bool) $this->option('producer-exit-confirmed'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            libxml_clear_errors();

            return self::FAILURE;
        }
        libxml_clear_errors();

        foreach ($result as $key => $value) {
            $this->line($key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value));
        }
        if (($result['status'] ?? null) === 'WARN') {
            $this->warn('Artifact integrity passed; unsupported pedestrian restriction relations make it unsafe for routing import.');
        }

        return self::SUCCESS;
    }
}
