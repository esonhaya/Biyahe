<?php

declare(strict_types=1);

use App\Console\Commands\VerifyOfflineWalkingCorpus;

require dirname(__DIR__).'/vendor/autoload.php';

$reflection = new ReflectionClass(VerifyOfflineWalkingCorpus::class);
$command = $reflection->newInstanceWithoutConstructor();
$writer = $reflection->getMethod('writeNewJson');
$path = sys_get_temp_dir().'/biyahe-batch043-corpus-report-'.bin2hex(random_bytes(12)).'.json';

try {
    $writer->invoke($command, $path, ['format' => 'fixture']);
    $original = file_get_contents($path);
    if (! is_string($original) || json_decode($original, true, flags: JSON_THROW_ON_ERROR) !== ['format' => 'fixture']) {
        throw new RuntimeException('The corpus writer produced invalid or incomplete JSON.');
    }
    if (file_exists($path.'.building')) {
        throw new RuntimeException('The corpus writer left an intermediate report behind.');
    }

    try {
        $writer->invoke($command, $path, ['format' => 'replacement']);
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'report path must be new')) {
            throw $exception;
        }
        if (file_get_contents($path) !== $original) {
            throw new RuntimeException('The existing corpus report was overwritten.');
        }
        echo "CORPUS_REPORT_WRITER=PASS checks=2\n";

        return;
    }

    throw new RuntimeException('The corpus writer accepted an existing report path.');
} finally {
    @unlink($path);
    @unlink($path.'.building');
}
