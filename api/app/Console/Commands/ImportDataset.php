<?php

namespace App\Console\Commands;

use App\Services\Data\DatasetImporter;
use App\Services\Data\DatasetValidationResult;
use App\Services\Data\DatasetValidator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ImportDataset extends Command
{
    protected $signature = 'biyahe:data:import {dataset : JSON dataset path or filename under database/data} {--dry-run : Validate without writing database records}';

    protected $description = 'Validate and transactionally import a Biyahe transport dataset.';

    public function handle(DatasetValidator $validator, DatasetImporter $importer): int
    {
        $path = $this->resolveDatasetPath((string) $this->argument('dataset'));
        $result = $validator->validate($path);

        $this->line('BIYAHE DATASET IMPORT');
        $this->line('dataset: '.$path);
        $this->line('records examined: '.$result->recordCount());
        $this->line('records valid: '.$result->validCount());
        $this->line('records rejected: '.$result->rejectedCount());
        $this->line('duplicates/conflicts: '.$result->duplicateCount());
        $this->printIssues($result);
        if (! $result->isValid() || $result->data === null) {
            $this->error('IMPORT ABORTED — validation failed; no records were written.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('DRY RUN — validation passed; no records were written.');

            return self::SUCCESS;
        }

        try {
            $summary = $importer->import($result->data);
        } catch (Throwable $exception) {
            $this->error('IMPORT ABORTED — transaction rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($summary->records() as $type => $counts) {
            $this->line(sprintf(
                '%-16s examined=%d imported=%d updated=%d skipped=%d',
                $type,
                $counts['examined'],
                $counts['imported'],
                $counts['updated'],
                $counts['skipped'],
            ));
        }
        $this->info('IMPORT PASS — '.$summary->total('imported').' imported, '.$summary->total('updated').' updated, '.$summary->total('skipped').' skipped.');

        return self::SUCCESS;
    }

    protected function resolveDatasetPath(string $dataset): string
    {
        $candidates = [$dataset, database_path('data/'.$dataset)];
        if (! str_ends_with($dataset, '.json')) {
            $candidates[] = database_path('data/'.$dataset.'.json');
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return (string) realpath($candidate);
            }
        }

        throw new RuntimeException('Dataset file not found: '.$dataset);
    }

    protected function printIssues(DatasetValidationResult $result): void
    {
        foreach ($result->errors() as $issue) {
            $this->error('ERROR '.$issue['path'].' — '.$issue['message']);
        }
        foreach ($result->warnings() as $issue) {
            $this->warn('WARN '.$issue['path'].' — '.$issue['message']);
        }
    }
}
