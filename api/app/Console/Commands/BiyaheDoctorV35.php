<?php

namespace App\Console\Commands;

use App\Services\Routing\OfflinePedestrianGraphReadinessV35;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Static Batch035 report generator; all inputs are saved evidence files. */
final class BiyaheDoctorV35 extends Command
{
    protected $signature = 'biyahe:doctor-v35 {--baseline= : Static Batch034 Doctor report} {--resources= : Spaced Batch035 resource samples} {--cancellation= : Owned-worker cancellation probe} {--cluster= : Read-only isolated cluster identity} {--import= : Read-only import/checkpoint evidence} {--coverage= : Hash-bound Batch032 coverage evidence} {--output= : New machine-readable report path}';

    protected $description = 'Report Batch035 safety and graph readiness from saved evidence without PostgreSQL access.';

    public function handle(OfflinePedestrianGraphReadinessV35 $readiness): int
    {
        try {
            $report = $readiness->build(
                $this->readJson((string) $this->option('baseline'), 'Batch034 Doctor report'),
                $this->readJson((string) $this->option('resources'), 'Batch035 resource evidence'),
                $this->readJson((string) $this->option('cancellation'), 'Batch035 cancellation evidence'),
                $this->readJson((string) $this->option('cluster'), 'Isolated cluster identity evidence'),
                $this->readJson((string) $this->option('import'), 'Batch035 import/checkpoint evidence'),
                $this->readJson((string) $this->option('coverage'), 'Batch032 coverage evidence'),
            );
            foreach ($report['checks'] as $check) {
                $this->line($check['status'].' '.$check['name'].': '.json_encode($check['evidence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $this->line('DOCTOR_V35='.$report['status'].' POSTGRESQL_CONTACTED=NO ACTIVATION=NO');
            $output = (string) $this->option('output');
            if ($output !== '') {
                $this->writeNewJsonFile($output, $report);
                $this->line('REPORT='.$output);
            }

            return $report['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function readJson(string $path, string $label): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path) || filesize($path) > 10_000_000) {
            throw new InvalidArgumentException($label.' must be a readable JSON file no larger than 10 MB.');
        }
        $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($document)) {
            throw new InvalidArgumentException($label.' must contain a JSON object.');
        }

        return $document;
    }

    private function writeNewJsonFile(string $path, array $document): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory) || file_exists($path) || file_exists($path.'.building')) {
            throw new InvalidArgumentException('Doctor output path must be new in an existing writable directory.');
        }
        $temporary = $path.'.building';
        $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not reserve Doctor report build path.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('Could not write the complete Doctor report.');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($temporary, $path)) {
            throw new RuntimeException('Could not atomically publish Doctor report.');
        }
    }
}
