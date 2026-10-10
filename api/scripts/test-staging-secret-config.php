<?php

declare(strict_types=1);

$environment = [];
function env(string $key, mixed $default = null): mixed
{
    global $environment;
    return $environment[$key] ?? $default;
}

function database_path(string $path = ''): string
{
    return '/tmp/'.ltrim($path, '/');
}

if (! class_exists('Illuminate\\Support\\Str')) {
    final class StagingTestStr
    {
        public static function slug(string $value): string
        {
            return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '', '-'));
        }
    }
    class_alias(StagingTestStr::class, 'Illuminate\\Support\\Str');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$directory = sys_get_temp_dir().'/biyahe-staging-secret-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $keyPath = $directory.'/app-key';
    $passwordPath = $directory.'/db-password';
    file_put_contents($keyPath, 'base64:staging-test-key'."\n");
    file_put_contents($passwordPath, 'test-password'."\r\n");

    $environment = ['APP_KEY_FILE' => $keyPath, 'APP_KEY' => 'fallback-key'];
    $app = require dirname(__DIR__).'/config/app.php';
    $assert($app['key'] === 'base64:staging-test-key', 'APP_KEY_FILE should take precedence and trim only line endings.');

    $environment = ['DB_CONNECTION' => 'pgsql', 'DB_PASSWORD_FILE' => $passwordPath, 'DB_PASSWORD' => 'fallback-password'];
    $database = require dirname(__DIR__).'/config/database.php';
    $assert($database['connections']['pgsql']['password'] === 'test-password', 'DB_PASSWORD_FILE should take precedence.');

    $environment = ['APP_KEY' => 'fallback-key'];
    $app = require dirname(__DIR__).'/config/app.php';
    $assert($app['key'] === 'fallback-key', 'Existing APP_KEY environment configuration remains compatible.');

    $environment = ['DB_CONNECTION' => 'pgsql', 'DB_PASSWORD' => 'fallback-password'];
    $database = require dirname(__DIR__).'/config/database.php';
    $assert($database['connections']['pgsql']['password'] === 'fallback-password', 'Existing DB_PASSWORD configuration remains compatible.');

    $environment = ['APP_KEY_FILE' => $directory.'/missing'];
    try {
        require dirname(__DIR__).'/config/app.php';
        $assert(false, 'A configured but missing key file must fail closed.');
    } catch (RuntimeException $error) {
        $assert(! str_contains($error->getMessage(), $directory), 'Secret errors must not disclose file paths.');
    }
} finally {
    @unlink($directory.'/app-key');
    @unlink($directory.'/db-password');
    @rmdir($directory);
}

fwrite(STDOUT, "STAGING_SECRET_CONFIG=PASS assertions={$assertions}\n");
