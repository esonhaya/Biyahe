<?php

namespace App\Console\Commands;

use App\Services\Routing\JourneyPlanningContext;
use App\Services\Routing\ResumableCoverageAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class BiyaheBatch018Audit extends Command
{
    protected $signature = 'biyahe:batch018:audit
        {--resume : Resume a compatible ignored runtime checkpoint}
        {--fresh : Replace the named ignored runtime checkpoint}
        {--pair= : Run one deterministic pair id}
        {--limit= : Maximum new pairs to process in this invocation}
        {--namespace= : Ignored runtime checkpoint namespace}
        {--json : Emit machine-readable summary}';

    protected $description = 'Run the 47-pair Metro Cebu coverage audit with atomic resumable checkpoints.';

    public function handle(ResumableCoverageAudit $audit): int
    {
        $namespace = (string) ($this->option('namespace') ?: config('biyahe.routing.batch018_audit_namespace', 'batch018-network-v1'));
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $namespace) !== 1) {
            $this->error('Invalid checkpoint namespace.');

            return self::INVALID;
        }
        $directory = storage_path('app/batch018/'.$namespace);
        $manifestPath = $directory.'/manifest.json';
        $resultsDirectory = $directory.'/results';

        if ($this->option('fresh') && is_dir($directory)) {
            File::deleteDirectory($directory);
        }
        File::ensureDirectoryExists($resultsDirectory);

        $fingerprint = $audit->fingerprint();
        $manifest = $this->loadManifest($manifestPath);
        if ($manifest !== null) {
            if (($manifest['audit_version'] ?? null) !== ResumableCoverageAudit::VERSION
                || ($manifest['network_fingerprint'] ?? null) !== $fingerprint) {
                $this->error('Existing checkpoint is incompatible with the current matrix/network fingerprint. Use a new namespace or --fresh.');

                return self::FAILURE;
            }
        } else {
            $manifest = [
                'audit_version' => ResumableCoverageAudit::VERSION,
                'network_fingerprint' => $fingerprint,
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'results' => [],
            ];
            $this->writeAtomic($manifestPath, $manifest);
        }

        $workUnits = $audit->workUnits();
        $selected = $workUnits;
        if ($this->option('pair') !== null) {
            $pairId = (string) $this->option('pair');
            $selected = array_values(array_filter($workUnits, static fn (array $unit): bool => $unit['id'] === $pairId));
            if ($selected === []) {
                $this->error('Unknown pair id: '.$pairId);

                return self::INVALID;
            }
        }
        if ($this->option('limit') !== null) {
            $selected = array_slice($selected, 0, min(max((int) $this->option('limit'), 1), count($selected)));
        }

        $places = $audit->places();
        $context = new JourneyPlanningContext((int) config('biyahe.routing.max_cached_patterns', 250));
        $processed = 0;
        foreach ($selected as $unit) {
            $previous = $manifest['results'][$unit['id']] ?? null;
            if (($this->option('resume') || $this->option('pair') === null)
                && is_array($previous)
                && ($previous['status'] ?? null) === 'COMPLETE') {
                continue;
            }

            $manifest['results'][$unit['id']] = [
                ...$unit,
                'status' => 'RUNNING',
                'started_at' => now()->toIso8601String(),
            ];
            $manifest['updated_at'] = now()->toIso8601String();
            $this->writeAtomic($manifestPath, $manifest);

            $connection = DB::connection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $result = $audit->run($unit, $places, $context);
            $result['query_count'] = count($connection->getQueryLog());
            $connection->disableQueryLog();
            $connection->flushQueryLog();
            $manifest['results'][$unit['id']] = $result;
            $manifest['updated_at'] = now()->toIso8601String();
            $this->writeAtomic($resultsDirectory.'/'.$unit['id'].'.json', $result);
            $this->writeAtomic($manifestPath, $manifest);
            $processed++;

            if (! $this->option('json')) {
                $this->line($unit['id'].': '.$result['status'].' '.($result['runtime_ms'] ?? 0).'ms');
            }
        }

        $summary = $audit->summarize($manifest['results']);
        $summary['checkpoint'] = [
            'namespace' => $namespace,
            'path' => $manifestPath,
            'processed_this_run' => $processed,
            'network_fingerprint' => $fingerprint,
            'resumable' => true,
        ];
        $manifest['summary'] = $summary;
        $manifest['updated_at'] = now()->toIso8601String();
        $this->writeAtomic($manifestPath, $manifest);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('BIYAHE BATCH 018 RESUMABLE COVERAGE AUDIT');
            foreach ($summary as $key => $value) {
                if ($key !== 'checkpoint') {
                    $this->line($key.': '.(is_array($value) ? json_encode($value) : $value));
                }
            }
            $this->line('checkpoint: '.$manifestPath);
        }

        return ($summary['pairs_failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function loadManifest(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $payload */
    private function writeAtomic(string $path, array $payload): void
    {
        $temporary = $path.'.tmp.'.getmypid();
        file_put_contents($temporary, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($temporary, $path);
    }
}
