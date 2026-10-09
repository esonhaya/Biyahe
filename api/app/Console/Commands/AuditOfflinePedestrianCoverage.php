<?php

namespace App\Console\Commands;

use App\Services\Routing\OfflinePedestrianCoverageAudit;
use App\Services\Routing\OfflinePedestrianGraphEvidenceAudit;
use App\Services\Routing\OfflinePedestrianGraphRouter;
use App\Services\Routing\OfflinePedestrianGraphValidator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AuditOfflinePedestrianCoverage extends Command
{
    protected $signature = 'biyahe:pedestrian:audit-offline-coverage {--graph= : Completed immutable SQLite graph artifact} {--graph-sha256= : Required expected graph artifact SHA-256} {--source-path= : Exact local OSM PBF used by the graph} {--source-sha256= : Required expected PBF SHA-256} {--backup-path= : Verified transport database backup used for the point export} {--backup-sha256= : Required expected backup SHA-256} {--transport-data= : JSON export of stops, places, transfers, and stored trust states} {--restriction-audit= : Existing source and graph bound relation inventory report} {--output= : New machine-readable coverage and route-corpus report path}';

    protected $description = 'Measure sourced stop, place, and transfer coverage against an immutable offline pedestrian graph.';

    public function handle(OfflinePedestrianGraphValidator $validator): int
    {
        $graphPath = (string) $this->option('graph');
        $expectedGraphHash = strtolower((string) $this->option('graph-sha256'));
        $sourcePath = (string) $this->option('source-path');
        $expectedSourceHash = strtolower((string) $this->option('source-sha256'));
        $backupPath = (string) $this->option('backup-path');
        $expectedBackupHash = strtolower((string) $this->option('backup-sha256'));
        $transportDataPath = (string) $this->option('transport-data');
        $restrictionAuditPath = (string) $this->option('restriction-audit');
        $outputPath = (string) $this->option('output');

        try {
            $this->assertFileAndHash($sourcePath, $expectedSourceHash, 'PBF');
            $actualGraphHash = $this->assertFileAndHash($graphPath, $expectedGraphHash, 'SQLite graph');
            $actualBackupHash = $this->assertFileAndHash($backupPath, $expectedBackupHash, 'transport backup');
            if (! is_file($transportDataPath) || ! is_readable($transportDataPath) || filesize($transportDataPath) > 2_000_000) {
                throw new InvalidArgumentException('Transport point export must be a readable JSON file no larger than 2 MB.');
            }
            if ($outputPath === '' || file_exists($outputPath) || file_exists($outputPath.'.building')
                || $restrictionAuditPath === '' || ! is_file($restrictionAuditPath) || ! is_readable($restrictionAuditPath)) {
                throw new InvalidArgumentException('Coverage output must be new; an existing readable restriction audit is required.');
            }
            $transportContents = file_get_contents($transportDataPath);
            if (! is_string($transportContents)) {
                throw new RuntimeException('Transport point export could not be read.');
            }
            $transportData = json_decode($transportContents, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($transportData)
                || ! hash_equals($actualBackupHash, strtolower((string) ($transportData['backup_sha256'] ?? '')))) {
                throw new InvalidArgumentException('Transport point export provenance does not match the verified backup file.');
            }

            $graphValidation = $validator->validate($graphPath, $sourcePath, $expectedSourceHash, $restrictionAuditPath);
            if (($graphValidation['artifact_ready'] ?? false) !== true || ($graphValidation['routing_ready'] ?? false) !== true) {
                throw new InvalidArgumentException('Graph validation did not establish an importable, snapshot-bound routing capability (restriction inventory: '
                    .($graphValidation['restriction_inventory_status'] ?? 'UNKNOWN').'; '
                    .($graphValidation['restriction_inventory_reason'] ?? $graphValidation['routing_ready_reason'] ?? 'no reason supplied').').');
            }
            $router = new OfflinePedestrianGraphRouter($graphPath, $expectedSourceHash, $graphValidation);
            $audit = (new OfflinePedestrianCoverageAudit($router))->audit($transportData, hash('sha256', $transportContents));
            $audit['quality_evidence'] = (new OfflinePedestrianGraphEvidenceAudit)->audit($graphPath, $router->componentSummary());
            $audit['graph_validation'] = [
                'artifact_ready' => true,
                'routing_ready' => true,
                'nodes' => $graphValidation['nodes'],
                'edges' => $graphValidation['edges'],
                'components' => $graphValidation['components'],
                'restriction_relations' => $graphValidation['relation_restrictions'],
                'restriction_counts' => $graphValidation['restriction_counts'],
                'restriction_inventory_complete' => $graphValidation['restriction_inventory_complete'],
                'routing_capabilities' => $graphValidation['routing_capabilities'],
                'routing_ready_reason' => $graphValidation['routing_ready_reason'],
                'graph_fingerprint' => $router->metadata()['graph_content_sha256'] ?? null,
                'artifact_sha256' => $actualGraphHash,
            ];
            $audit['complete'] = true;

            $this->writeNewJsonFile($outputPath, $audit);
            $this->info('Offline graph coverage audit completed.');
            $this->line('report: '.$outputPath);
            $this->line('stops: '.count($audit['coverage']['stops']['records']));
            $this->line('places: '.count($audit['coverage']['places']['records']));
            $this->line('journeys: '.$audit['journey_corpus']['case_count']);
            $this->line('routes_found: '.$audit['journey_corpus']['routes_found']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function assertFileAndHash(string $path, string $expectedHash, string $label): string
    {
        if (! is_file($path) || ! is_readable($path) || ! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            throw new InvalidArgumentException(ucfirst($label).' and its expected SHA-256 are required.');
        }
        $actualHash = hash_file('sha256', $path);
        if (! is_string($actualHash) || ! hash_equals($expectedHash, strtolower($actualHash))) {
            throw new InvalidArgumentException(ucfirst($label).' SHA-256 does not match the expected source identity.');
        }

        return strtolower($actualHash);
    }

    private function writeNewJsonFile(string $path, array $document): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new InvalidArgumentException('Coverage report directory must already exist and be writable.');
        }
        $temporaryPath = $path.'.building';
        if (file_exists($path) || file_exists($temporaryPath)) {
            throw new InvalidArgumentException('Coverage output or its build temporary path already exists.');
        }
        $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($temporaryPath, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create the new report build file.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('Could not write the complete coverage report.');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($temporaryPath, $path)) {
            throw new RuntimeException('Could not atomically publish the new coverage report.');
        }
    }
}
