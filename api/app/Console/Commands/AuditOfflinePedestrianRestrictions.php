<?php

namespace App\Console\Commands;

use App\Services\Routing\OfflinePedestrianGraphValidator;
use App\Services\Routing\OfflinePedestrianRestrictionAudit;
use Illuminate\Console\Command;
use PDO;
use RuntimeException;
use Throwable;

final class AuditOfflinePedestrianRestrictions extends Command
{
    protected $signature = 'biyahe:pedestrian:audit-restrictions {--graph= : Completed SQLite graph artifact} {--source-path= : Verified local source PBF} {--source-scan-evidence= : Completed Osmium relation-tag scan JSON} {--output= : New machine-readable audit report path} {--sidecar= : New artifact-adjacent audit sidecar path}';

    protected $description = 'Classify the offline graph restriction inventory and bind it to source and graph fingerprints.';

    public function handle(OfflinePedestrianRestrictionAudit $audit, OfflinePedestrianGraphValidator $validator): int
    {
        $graphPath = trim((string) $this->option('graph'));
        $sourcePath = trim((string) $this->option('source-path'));
        $evidencePath = trim((string) $this->option('source-scan-evidence'));
        $outputPath = trim((string) $this->option('output'));
        $sidecarPath = trim((string) $this->option('sidecar'));
        if ($graphPath === '' || $sourcePath === '' || $evidencePath === '' || $outputPath === '' || $sidecarPath === ''
            || ! is_file($graphPath) || ! is_readable($graphPath)
            || ! is_file($sourcePath) || ! is_readable($sourcePath)
            || ! is_file($evidencePath) || ! is_readable($evidencePath)) {
            $this->error('A readable graph, source PBF, and source-scan evidence file plus new output and sidecar paths are required.');

            return self::FAILURE;
        }
        if (realpath($graphPath) === realpath($outputPath) || realpath($graphPath) === realpath($sidecarPath)
            || realpath($sourcePath) === realpath($outputPath) || realpath($sourcePath) === realpath($sidecarPath)
            || $outputPath === $sidecarPath || file_exists($outputPath) || file_exists($sidecarPath)) {
            $this->error('Audit output and sidecar must be new paths and cannot overwrite the graph or source.');

            return self::FAILURE;
        }

        try {
            $sourceHash = hash_file('sha256', $sourcePath);
            $evidence = json_decode((string) file_get_contents($evidencePath), true, 64, JSON_THROW_ON_ERROR);
            if (! is_string($sourceHash) || ! is_array($evidence)) {
                throw new RuntimeException('Source scan evidence is not a JSON object or the PBF hash could not be read.');
            }
            $sqlite = new PDO('sqlite:'.$graphPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $sqlite->exec('PRAGMA query_only=ON');
            $report = $audit->build($sqlite, $evidence);
            if (! hash_equals(strtolower((string) $report['source']['sha256']), strtolower($sourceHash))) {
                throw new RuntimeException('The actual local PBF SHA-256 does not match the graph and scan evidence.');
            }
            $contents = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            $this->writeNewFile($outputPath, $contents);
            $this->writeNewFile($sidecarPath, $contents);
            $sqlite = null;

            $validation = $validator->validate($graphPath, $sourcePath, null, $sidecarPath);
            $counts = $validation['restriction_counts'];
            $this->line('source_relations: '.$validation['restriction_source_relation_count']);
            $this->line('stored_relations: '.$counts['total']);
            $this->line('vehicle_only: '.$counts['vehicle_only']);
            $this->line('pedestrian_applicable: '.$counts['pedestrian_applicable']);
            $this->line('pedestrian_exempt: '.$counts['pedestrian_exempt']);
            $this->line('conditional: '.$counts['conditional']);
            $this->line('via_node: '.$counts['via_node']);
            $this->line('via_way: '.$counts['via_way']);
            $this->line('ambiguous: '.$counts['ambiguous']);
            $this->line('malformed: '.$counts['malformed']);
            $this->line('unsupported_applicable: '.$validation['unsupported_applicable_restrictions']);
            $this->line('routing_ready: '.($validation['routing_ready'] ? 'yes' : 'no'));
            $this->line('report: '.$outputPath);
            $this->line('sidecar: '.$sidecarPath);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Restriction audit failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function writeNewFile(string $path, string $contents): void
    {
        if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            throw new RuntimeException('Audit output parent directory is not writable.');
        }
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Audit output path exists or could not be reserved without clobbering.');
        }
        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($stream, substr($contents, $offset));
                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('Audit report could not be written completely.');
                }
                $offset += $written;
            }
            if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                throw new RuntimeException('Audit report could not be flushed to storage.');
            }
        } finally {
            fclose($stream);
        }
    }
}
