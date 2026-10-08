<?php

namespace Tests\Support;

use LogicException;

final class DatabaseTestSafety
{
    /**
     * Refuse a test connection that can target a shared or production-named database.
     *
     * @param  array<string, mixed>  $databaseConfig
     */
    public static function assertSafeDefaultConnection(array $databaseConfig): void
    {
        $connectionName = $databaseConfig['default'] ?? null;
        $connection = is_string($connectionName)
            ? ($databaseConfig['connections'][$connectionName] ?? null)
            : null;

        if (! is_array($connection)) {
            throw new LogicException('Refusing database test setup: the default database connection is not configured.');
        }

        $driver = (string) ($connection['driver'] ?? $connectionName);
        $database = $connection['database'] ?? null;
        $url = $connection['url'] ?? null;

        if (is_string($url) && trim($url) !== '') {
            $parts = parse_url($url);
            if ($parts === false) {
                throw new LogicException('Refusing database test setup: the default database URL is malformed.');
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $driver = match ($scheme) {
                'postgres', 'postgresql' => 'pgsql',
                'mssql' => 'sqlsrv',
                'mysql2' => 'mysql',
                'sqlite3' => 'sqlite',
                default => $scheme !== '' ? $scheme : $driver,
            };

            $path = (string) ($parts['path'] ?? '');
            if ($path !== '' && $path !== '/') {
                $database = ltrim($path, '/');
            }
        }

        if (is_string($database) && str_contains(strtolower($database), 'prod')) {
            throw new LogicException('Refusing database test setup: production-named databases are protected.');
        }

        if ($driver === 'sqlite' && in_array($database, [':memory:', 'file::memory:'], true)) {
            return;
        }

        if (is_string($database) && preg_match('/(?:^|[\/_.-])(?:test|testing)(?:$|[\/_.-])/i', $database) === 1) {
            return;
        }

        throw new LogicException(
            'Refusing database test setup: use SQLite :memory: or a dedicated database with a standalone test name.'
        );
    }
}
