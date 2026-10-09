<?php

namespace App\Console\Commands;

use App\Services\Routing\LocalPedestrianGraphRouter;
use App\Services\Routing\OfflinePedestrianRouteParityAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Reads only an exact inactive staging dataset; never activates or edits it. */
final class AuditOfflinePedestrianRouteParity extends Command
{
    protected $signature = 'biyahe:pedestrian:audit-staged-route-parity {--corpus= : Completed Batch032 real Cebu journey corpus} {--dataset-id= : Exact validated inactive graph dataset ID} {--graph-sha256= : Verified immutable graph SHA-256} {--source-sha256= : Verified PBF SHA-256} {--pgdata= : Exact new Batch033 isolated cluster data directory} {--port= : Exact loopback PostgreSQL port} {--output= : New parity JSON report path}';

    protected $description = 'Compare source-backed offline Cebu routes with the exact inactive graph in isolated staging.';

    public function handle(LocalPedestrianGraphRouter $router, OfflinePedestrianRouteParityAudit $audit): int
    {
        try {
            $graphSha256 = strtolower((string) $this->option('graph-sha256'));
            $sourceSha256 = strtolower((string) $this->option('source-sha256'));
            $corpusPath = (string) $this->option('corpus');
            $outputPath = (string) $this->option('output');
            $datasetId = (int) $this->option('dataset-id');
            $pgData = (string) $this->option('pgdata');
            $port = (string) $this->option('port');
            if (! preg_match('/^[a-f0-9]{64}$/', $graphSha256)
                || ! preg_match('/^[a-f0-9]{64}$/', $sourceSha256)
                || $datasetId < 1 || ! is_file($corpusPath) || ! is_readable($corpusPath)
                || filesize($corpusPath) > 5_000_000 || $outputPath === ''
                || file_exists($outputPath) || file_exists($outputPath.'.building')) {
                throw new InvalidArgumentException('Parity requires verified graph/source hashes, a readable complete corpus, a dataset ID, and a new output path.');
            }
            $connection = DB::connection();
            $database = (string) $connection->getDatabaseName();
            if ($connection->getDriverName() !== 'pgsql'
                || (string) $connection->getConfig('host') !== '127.0.0.1'
                || (string) $connection->getConfig('port') !== $port
                || preg_match('/(?:^|[_-])(?:staging|test|testing|sandbox|scratch)(?:$|[_-])/i', $database) !== 1
                || preg_match('/(?:^|[_-])(?:prod|production)(?:$|[_-])/i', $database) === 1
                || ! is_dir($pgData) || realpath($pgData) === false
                || preg_match('#/\.cache/biyahe/batch033-isolated-[A-Za-z0-9_-]+/data$#', (string) realpath($pgData)) !== 1) {
                throw new InvalidArgumentException('Parity is restricted to the explicit loopback Batch033 isolated staging cluster.');
            }
            $server = DB::selectOne("SELECT current_setting('data_directory') AS data_directory");
            $actualPgData = is_object($server) ? realpath((string) ($server->data_directory ?? '')) : false;
            if ($actualPgData === false || ! hash_equals((string) realpath($pgData), $actualPgData)) {
                throw new InvalidArgumentException('Connected PostgreSQL data_directory does not match the requested Batch033 isolated cluster.');
            }
            $corpusContents = file_get_contents($corpusPath);
            if (! is_string($corpusContents)) {
                throw new RuntimeException('Journey corpus could not be read.');
            }
            $corpus = json_decode($corpusContents, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($corpus)
                || ! hash_equals($sourceSha256, strtolower((string) ($corpus['source']['graph_source_sha256'] ?? '')))
                || ! hash_equals($graphSha256, strtolower((string) ($corpus['graph_validation']['artifact_sha256'] ?? '')))) {
                throw new InvalidArgumentException('Journey corpus graph/PBF identities do not match the expected verified snapshot.');
            }
            $dataset = DB::table('pedestrian_network_datasets')->where('id', $datasetId)->first();
            $metadata = $dataset === null ? null : json_decode((string) $dataset->metadata, true);
            if ($dataset === null || (bool) $dataset->is_active || ! is_array($metadata)
                || ($metadata['import_state'] ?? null) !== 'VALIDATED'
                || ($metadata['routing_ready'] ?? false) !== true
                || ($metadata['activation'] ?? null) !== 'NOT_ACTIVATED'
                || ! hash_equals($graphSha256, strtolower((string) ($metadata['graph_sha256'] ?? '')))
                || ! hash_equals($sourceSha256, strtolower((string) ($metadata['source_details']['source_checksum_sha256'] ?? '')))) {
                throw new InvalidArgumentException('Selected dataset is not the exact validated inactive graph for this source snapshot.');
            }

            config(['biyahe.pedestrian.maximum_snap_candidates' => 1]);
            $report = $audit->audit(
                $corpus,
                fn ($request) => $router->routeValidatedStaging($request, $datasetId, $graphSha256, $sourceSha256),
            );
            $report['identity'] = [
                'graph_sha256' => $graphSha256,
                'source_sha256' => $sourceSha256,
                'graph_fingerprint' => strtolower((string) ($metadata['graph_fingerprint'] ?? '')),
                'dataset_id' => $datasetId,
                'stable_id' => (string) $dataset->stable_id,
                'database' => $database,
                'port' => $port,
                'pgdata' => $actualPgData,
                'is_active' => false,
                'activation' => 'NO',
            ];
            $report['corpus_sha256'] = hash('sha256', $corpusContents);
            $report['snap_policy_meters'] = 25;
            $report['field_safety_verified'] = false;
            $this->writeNewJsonFile($outputPath, $report);
            $this->line('PARITY='.$report['status'].' cases='.$report['case_count'].' matches='.$report['matches'].' mismatches='.$report['mismatches']);
            $this->line('REASON_CODE_EXACT='.$report['reason_code_exact_matches'].' REASON_FAMILY='.$report['reason_family_matches']);
            $this->line('REPORT='.$outputPath.' ACTIVATION=NO');

            return $report['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function writeNewJsonFile(string $path, array $document): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory) || file_exists($path) || file_exists($path.'.building')) {
            throw new InvalidArgumentException('Parity report destination must be new in an existing writable directory.');
        }
        $temporary = $path.'.building';
        $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not reserve the parity report build path.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('Could not write the complete parity report.');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($temporary, $path)) {
            throw new RuntimeException('Could not atomically publish the parity report.');
        }
    }
}
