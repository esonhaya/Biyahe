<?php

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
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
}
