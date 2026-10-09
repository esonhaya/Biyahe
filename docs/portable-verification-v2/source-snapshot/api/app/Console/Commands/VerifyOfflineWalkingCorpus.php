<?php

namespace App\Console\Commands;

use App\Services\Routing\OfflineJourneyWalkingPreview;
use App\Services\Routing\OfflineWalkingPreviewException;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class VerifyOfflineWalkingCorpus extends Command
{
    protected $signature = 'biyahe:pedestrian:verify-offline-corpus {--output= : New JSON path for deterministic replay evidence}';

    protected $description = 'Replay the ten stored Batch032 Cebu walking cases without reading the source PBF or PostgreSQL.';

    public function handle(OfflineJourneyWalkingPreview $preview): int
    {
        $output = (string) $this->option('output');
        if ($output === '' || file_exists($output) || file_exists($output.'.building')) {
            $this->error('Provide a new --output path; existing files are never overwritten.');

            return self::FAILURE;
        }

        try {
            $report = $preview->verifyStoredCorpus();
            $this->writeNewJson($output, $report);
            $this->line('CORPUS_STATUS='.$report['status']);
            $this->line('CASES='.$report['cases_total']);
            $this->line('ROUTES_FOUND='.$report['routes_found']);
            $this->line('ROUTES_UNAVAILABLE='.$report['routes_unavailable']);
            $this->line('GEOMETRY_MATCHES='.$report['geometry_matches'].'/'.$report['cases_total']);
            $this->line('report: '.$output);

            return $report['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
        } catch (OfflineWalkingPreviewException $exception) {
            $this->error($exception->reasonCode.': '.$exception->getMessage());
        } catch (Throwable $exception) {
            $this->error('offline_corpus_failed: '.$exception->getMessage());
        }

        return self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function writeNewJson(string $path, array $report): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory) || file_exists($path) || file_exists($path.'.building')) {
            throw new RuntimeException('The output directory must exist and the report path must be new.');
        }
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('The corpus report could not be serialized.', 0, $exception);
        }
        $temporary = $path.'.building';
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('The corpus report temporary file could not be created.');
        }
        try {
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                throw new RuntimeException('The complete corpus report could not be written.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);
        if (file_exists($path) || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The corpus report could not be atomically published without overwriting a file.');
        }
    }
}
