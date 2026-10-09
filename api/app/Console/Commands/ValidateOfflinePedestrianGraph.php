<?php

namespace App\Console\Commands;

use App\Services\Routing\OfflinePedestrianGraphValidator;
use Illuminate\Console\Command;
use Throwable;

final class ValidateOfflinePedestrianGraph extends Command
{
    protected $signature = 'biyahe:pedestrian:validate-offline {file : Completed SQLite graph artifact} {--source-path= : Verified local source PBF} {--source-sha256= : Optional expected source SHA-256}';

    protected $description = 'Validate offline graph provenance, integrity, topology, directions and geometry.';

    public function handle(OfflinePedestrianGraphValidator $validator): int
    {
        try {
            $result = $validator->validate((string) $this->argument('file'), (string) $this->option('source-path'), $this->option('source-sha256') ?: null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        foreach ($result as $key => $value) {
            $display = is_bool($value) ? ($value ? 'yes' : 'no') : (is_array($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $value);
            $this->line($key.': '.$display);
        }
        if (! $result['routing_ready']) {
            $this->warn('Artifact is structurally complete, but restriction inventory or semantics prevent routing import: '.$result['routing_ready_reason']);
        }

        return self::SUCCESS;
    }
}
