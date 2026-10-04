<?php

namespace App\Console\Commands;

use App\Services\Data\DatasetValidationResult;
use App\Services\Data\DatasetValidator;
use Illuminate\Console\Command;
use RuntimeException;

class ValidateDataset extends Command
{
    protected $signature = 'biyahe:data:validate {dataset : JSON dataset path or filename under database/data}';

    protected $description = 'Validate a human-reviewable Biyahe transport dataset.';

    public function handle(DatasetValidator $validator): int
    {
        $path = $this->resolveDatasetPath((string) $this->argument('dataset'));
        $result = $validator->validate($path);

        $this->line('BIYAHE DATASET VALIDATION');
        $this->line('dataset: '.$path);
        $this->line('records examined: '.$result->recordCount());
        $this->line('records valid: '.$result->validCount());
        $this->line('records rejected: '.$result->rejectedCount());
        $this->line('duplicates/conflicts: '.$result->duplicateCount());
        $this->printIssues($result);
        $this->line($result->isValid() ? 'VALIDATION PASS' : 'VALIDATION FAIL');

        return $result->isValid() ? self::SUCCESS : self::FAILURE;
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
