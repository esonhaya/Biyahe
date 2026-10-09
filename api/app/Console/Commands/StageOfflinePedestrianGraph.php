<?php

namespace App\Console\Commands;

use App\Services\Routing\PedestrianNetworkImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** Stages one exact SQLite graph only into a loopback isolated test database. */
final class StageOfflinePedestrianGraph extends Command
{
    protected $signature = 'biyahe:pedestrian:stage-offline-graph {--graph= : Immutable validated SQLite graph} {--graph-sha256= : Expected graph artifact SHA-256} {--source= : Local PBF used to construct the graph} {--source-sha256= : Expected PBF SHA-256} {--database= : Exact isolated database identity} {--port= : Exact loopback PostgreSQL port} {--pgdata= : Exact data directory of the Batch033 isolated cluster} {--restart-incomplete : Explicitly discard only a matching inactive partial import and restart from source beginning}';

    protected $description = 'Stream a verified offline pedestrian graph into an inactive, isolated PostgreSQL staging database.';

    public function handle(PedestrianNetworkImporter $importer): int
    {
        try {
            $graphPath = (string) $this->option('graph');
            $sourcePath = (string) $this->option('source');
            $graphSha256 = strtolower((string) $this->option('graph-sha256'));
            $sourceSha256 = strtolower((string) $this->option('source-sha256'));
            $database = (string) $this->option('database');
            $port = (string) $this->option('port');
            $pgData = (string) $this->option('pgdata');
            $connection = DB::connection();
            $configuredPort = (string) $connection->getConfig('port');
            $configuredHost = (string) $connection->getConfig('host');
            if ($connection->getDriverName() !== 'pgsql'
                || $configuredHost !== '127.0.0.1'
                || $configuredPort === '' || $configuredPort !== $port
                || (string) $connection->getDatabaseName() !== $database
                || preg_match('/(?:^|[_-])(?:staging|test|testing|sandbox|scratch)(?:$|[_-])/i', $database) !== 1
                || preg_match('/(?:^|[_-])(?:prod|production)(?:$|[_-])/i', $database) === 1
                || ! is_dir($pgData) || realpath($pgData) === false
                || preg_match('#/\.cache/biyahe/batch033-isolated-[A-Za-z0-9_-]+/data$#', (string) realpath($pgData)) !== 1) {
                throw new InvalidArgumentException('Import requires the exact loopback PostgreSQL port and an isolated staging/test database name.');
            }
            $server = DB::selectOne("SELECT current_setting('data_directory') AS data_directory");
            $actualPgData = is_object($server) ? realpath((string) ($server->data_directory ?? '')) : false;
            if ($actualPgData === false || ! hash_equals((string) realpath($pgData), $actualPgData)) {
                throw new InvalidArgumentException('Connected PostgreSQL data_directory does not match the explicit new Batch033 isolated cluster.');
            }
            putenv('BIYAHE_ISOLATED_PGDATA='.$actualPgData);
            if (! is_file($graphPath) || ! is_readable($graphPath) || ! is_file($sourcePath) || ! is_readable($sourcePath)
                || ! preg_match('/^[a-f0-9]{64}$/', $graphSha256)
                || ! preg_match('/^[a-f0-9]{64}$/', $sourceSha256)) {
                throw new InvalidArgumentException('Readable graph/source files and exact SHA-256 identities are required.');
            }

            $restartIncomplete = (bool) $this->option('restart-incomplete');
            $this->line('IMPORT_PREFLIGHT=START graph_sha256='.$graphSha256.' source_sha256='.$sourceSha256.' database='.$database.' port='.$port.' pgdata='.$actualPgData.' restart_incomplete='.($restartIncomplete ? 'YES' : 'NO'));
            $progressFile = getenv('BIYAHE_RESOURCE_PROGRESS_FILE');
            if (! is_string($progressFile) || $progressFile === '') {
                $progressFile = null;
            } elseif (! is_dir(dirname($progressFile)) || file_exists($progressFile)) {
                throw new InvalidArgumentException('Resource progress file must be new in an existing writable directory.');
            } else {
                $progressHandle = fopen($progressFile, 'x');
                if ($progressHandle === false) {
                    throw new InvalidArgumentException('Could not reserve the new resource progress file.');
                }
                fclose($progressHandle);
            }
            $progress = function (string $phase, array $counts): void {
                $line = sprintf(
                    'IMPORT_PROGRESS=%s nodes=%d/%d edges=%d/%d node_batches=%d edge_batches=%d elapsed_ms=%d php_peak_rss_kib=%d',
                    $phase,
                    (int) ($counts['nodes_committed'] ?? 0), (int) ($counts['expected_nodes'] ?? 0),
                    (int) ($counts['edges_committed'] ?? 0), (int) ($counts['expected_edges'] ?? 0),
                    (int) ($counts['node_batches_committed'] ?? 0), (int) ($counts['edge_batches_committed'] ?? 0),
                    (int) ($counts['elapsed_milliseconds'] ?? 0), $this->phpResidentMemoryKib(),
                );
                $this->line($line);
                $progressFile = getenv('BIYAHE_RESOURCE_PROGRESS_FILE');
                if (is_string($progressFile) && $progressFile !== '') {
                    file_put_contents($progressFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            };
            $result = $importer->importOfflineSqlite(
                $graphPath,
                $sourcePath,
                $database,
                $graphSha256,
                $sourceSha256,
                $progress,
                $restartIncomplete,
            );
            $this->info('IMPORT_STATE=VALIDATED activation=NO is_active=NO');
            $this->line('NODES='.$result->nodes.' EDGES='.$result->edges.' ALREADY_IMPORTED='.($result->alreadyImported ? 'YES' : 'NO'));
            $this->line('DURATION_MS='.$result->runtimeMilliseconds);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('IMPORT_STATE=FAILED error='.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function phpResidentMemoryKib(): int
    {
        if (! is_readable('/proc/self/status')) {
            return 0;
        }
        $status = file_get_contents('/proc/self/status');
        if (! is_string($status) || preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
