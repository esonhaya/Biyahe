<?php

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\DatabaseTestSafety;

class DatabaseTestSafetyTest extends TestCase
{
    public function test_sqlite_memory_is_allowed(): void
    {
        $result = DatabaseTestSafety::assertSafeDefaultConnection([
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]);

        $this->assertNull($result);
    }

    public function test_production_named_database_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        DatabaseTestSafety::assertSafeDefaultConnection([
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => ['driver' => 'pgsql', 'database' => 'biyahe'],
            ],
        ]);
    }

    public function test_production_named_database_is_rejected_even_with_a_test_suffix(): void
    {
        $this->expectException(LogicException::class);

        DatabaseTestSafety::assertSafeDefaultConnection([
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => ['driver' => 'pgsql', 'database' => 'biyaheprod_test'],
            ],
        ]);
    }

    public function test_dedicated_test_database_is_allowed(): void
    {
        $result = DatabaseTestSafety::assertSafeDefaultConnection([
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => ['driver' => 'pgsql', 'database' => 'biyahe_test'],
            ],
        ]);

        $this->assertNull($result);
    }

    public function test_connection_url_cannot_switch_to_a_production_database(): void
    {
        $this->expectException(LogicException::class);

        DatabaseTestSafety::assertSafeDefaultConnection([
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'url' => 'postgresql://tester:secret@localhost/biyahe',
                ],
            ],
        ]);
    }

    public function test_graph_updater_refuses_production_targets_before_creating_files_or_networking(): void
    {
        $script = dirname(__DIR__, 2).'/scripts/update-cebu-pedestrian-graph.sh';

        foreach (['', 'biyahe', 'biyahe_prod', 'biyahe_production_test', 'custom-prod-staging'] as $database) {
            $dataDirectory = sys_get_temp_dir().'/biyahe-graph-updater-'.bin2hex(random_bytes(6));
            $process = new Process(['bash', $script, '20261007'], null, [
                'BIYAHE_DB_CONNECTION' => 'pgsql',
                'BIYAHE_DB_DATABASE' => $database,
                'BIYAHE_PEDESTRIAN_DATA_DIR' => $dataDirectory,
                'DB_URL' => 'postgresql://tester:secret@localhost/biyahe',
            ]);
            $process->run();

            $this->assertSame(1, $process->getExitCode(), $database.': '.$process->getErrorOutput());
            $this->assertStringContainsString('refusing', strtolower($process->getErrorOutput()));
            $this->assertDirectoryDoesNotExist($dataDirectory);
        }
    }
}
