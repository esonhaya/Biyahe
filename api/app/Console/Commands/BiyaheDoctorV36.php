<?php

namespace App\Console\Commands;

use App\Services\Routing\OfflinePedestrianGraphReadinessV36;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Static release-gate Doctor using saved evidence only; it never contacts PostgreSQL. */
final class BiyaheDoctorV36 extends Command
{
    protected $signature = 'biyahe:doctor-v36 {--coverage= : Batch032 source-bound route and coverage corpus} {--doctor-v35= : Static Batch035 readiness evidence} {--package-manifest= : Portable staging package manifest} {--package-verification= : Byte-verified package report} {--validation= : Current static and fixture validation results} {--output= : New machine-readable report path}';

    protected $description = 'Report separate offline, package, staging, route-parity, field, and beta readiness levels.';

    public function handle(OfflinePedestrianGraphReadinessV36 $readiness): int
    {
        try {
            $manifestPath = (string) $this->option('package-manifest');
            $manifestSha256 = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : false;
            if (! is_string($manifestSha256)) {
                throw new InvalidArgumentException('Portable staging package manifest must be a readable file.');
            }
            $coveragePath = (string) $this->option('coverage');
            $coverageSha256 = is_file($coveragePath) ? hash_file('sha256', $coveragePath) : false;
            if (! is_string($coverageSha256)) {
                throw new InvalidArgumentException('Batch032 coverage corpus must be a readable file.');
            }
            $report = $readiness->build(
                $this->readJson($coveragePath, 'Batch032 coverage and journey evidence'),
                $this->readJson((string) $this->option('doctor-v35'), 'Batch035 static Doctor evidence'),
                $this->readJson($manifestPath, 'Portable package manifest'),
                $this->readJson((string) $this->option('package-verification'), 'Portable package verification evidence'),
                $this->readJson((string) $this->option('validation'), 'Batch036 validation evidence'),
                strtolower($manifestSha256),
                strtolower($coverageSha256),
            );
            foreach ($report['readiness_levels'] as $level) {
                $this->line($level['status'].' '.$level['name'].': '.json_encode($level['evidence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $this->line('DOCTOR_V36='.$report['overall_release_gate'].' POSTGRESQL_CONTACTED=NO ACTIVATION=NO');
            $output = (string) $this->option('output');
            if ($output !== '') {
                $this->writeNewJsonFile($output, $report);
                $this->line('REPORT='.$output);
            }

            return $report['overall_release_gate'] === 'PASS' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function readJson(string $path, string $label): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path) || filesize($path) > 20_000_000) {
            throw new InvalidArgumentException($label.' must be a readable JSON file no larger than 20 MB.');
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
        $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($path.'.building', 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not reserve the Doctor report build path.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('Could not write the complete Doctor report.');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($path.'.building', $path)) {
            throw new RuntimeException('Could not atomically publish the Doctor report.');
        }
    }
}
