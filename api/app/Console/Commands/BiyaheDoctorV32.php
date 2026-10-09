<?php

namespace App\Console\Commands;

use App\Services\Routing\OfflinePedestrianGraphReadinessReport;
use App\Services\Routing\OfflinePedestrianGraphValidator;
use App\Services\Routing\PedestrianFailureResponseContractAudit;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Static, evidence-bound graph readiness report. This command never contacts PostgreSQL. */
final class BiyaheDoctorV32 extends Command
{
    protected $signature = 'biyahe:doctor-v32 {--graph= : Immutable validated SQLite graph} {--graph-sha256= : Expected graph file SHA-256} {--source-path= : Verified source PBF} {--source-sha256= : Expected source PBF SHA-256} {--restriction-audit= : Existing source and graph bound Batch031 restriction inventory} {--coverage-report= : Completed source-backed coverage and route corpus JSON} {--staging-report= : Isolated staging attempt evidence JSON} {--output= : Optional new machine-readable Doctor report}';

    protected $description = 'Report source, graph, route coverage, staging, and beta readiness without a database connection.';

    public function handle(OfflinePedestrianGraphValidator $validator): int
    {
        try {
            $graphPath = (string) $this->option('graph');
            $sourcePath = (string) $this->option('source-path');
            $coveragePath = (string) $this->option('coverage-report');
            $stagingPath = (string) $this->option('staging-report');
            $actualGraphHash = $this->verifyHash($graphPath, (string) $this->option('graph-sha256'), 'graph');
            $actualSourceHash = $this->verifyHash($sourcePath, (string) $this->option('source-sha256'), 'source PBF');
            $restrictionAuditPath = (string) $this->option('restriction-audit');
            $graphValidation = $validator->validate(
                $graphPath,
                $sourcePath,
                strtolower((string) $this->option('source-sha256')),
                $restrictionAuditPath,
            );
            $restrictionAudit = $this->readJson($restrictionAuditPath, 'restriction audit');
            $graphValidation['restriction_source_classification_counts'] = $restrictionAudit['source_classification_counts'] ?? [];
            $graphValidation['restriction_source_omitted_count'] = (int) ($restrictionAudit['source_scan']['omitted_relation_count'] ?? 0);
            $graphValidation['restriction_audit_sha256'] = strtolower((string) hash_file('sha256', $restrictionAuditPath));
            $coverage = $this->readJson($coveragePath, 'coverage report');
            $staging = $this->readJson($stagingPath, 'isolated staging report');
            $coverage['failure_response_contract'] = (new PedestrianFailureResponseContractAudit)->audit(dirname(base_path()));
            $report = (new OfflinePedestrianGraphReadinessReport)->build($actualGraphHash, $actualSourceHash, $graphValidation, $coverage, $staging);
            $checks = $report['checks'];
            $status = $report['status'];

            foreach ($checks as $check) {
                $this->line($check['status'].' '.$check['name'].': '.json_encode($check['evidence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $this->line('PRIVATE_BETA='.$checks[count($checks) - 1]['status']);
            $this->line('ACTIVATION=NO');
            $this->line('DOCTOR_V32='.$status);
            if ((string) $this->option('output') !== '') {
                $this->writeNewJsonFile((string) $this->option('output'), $report);
                $this->line('report: '.(string) $this->option('output'));
            }

            return $status === 'PASS' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function verifyHash(string $path, string $expectedHash, string $label): string
    {
        $expectedHash = strtolower($expectedHash);
        if (! is_file($path) || ! is_readable($path) || ! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            throw new InvalidArgumentException(ucfirst($label).' file and expected SHA-256 are required.');
        }
        $actualHash = hash_file('sha256', $path);
        if (! is_string($actualHash) || ! hash_equals($expectedHash, strtolower($actualHash))) {
            throw new InvalidArgumentException(ucfirst($label).' file SHA-256 does not match the expected identity.');
        }

        return strtolower($actualHash);
    }

    private function readJson(string $path, string $label): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5_000_000) {
            throw new InvalidArgumentException(ucfirst($label).' must be a readable JSON file no larger than 5 MB.');
        }
        $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($document)) {
            throw new InvalidArgumentException(ucfirst($label).' JSON must contain an object.');
        }

        return $document;
    }

    private function writeNewJsonFile(string $path, array $report): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory) || file_exists($path) || file_exists($path.'.building')) {
            throw new InvalidArgumentException('Doctor report path must be new and its existing directory writable.');
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($path.'.building', 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create the Doctor report build file.');
        }
        try {
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                throw new RuntimeException('Could not write the complete Doctor report.');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($path.'.building', $path)) {
            throw new RuntimeException('Could not publish the Doctor report.');
        }
    }
}
