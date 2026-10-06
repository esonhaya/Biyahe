<?php

namespace App\Console\Commands;

use App\Services\FieldSurveys\SurveyImportValidator;
use Illuminate\Console\Command;

final class ValidateSurveyDataset extends Command
{
    protected $signature = 'biyahe:surveys:validate {dataset : Version 1 offline survey JSON file}';

    protected $description = 'Validate an offline Biyahe field-survey package without writing data.';

    public function handle(SurveyImportValidator $validator): int
    {
        $result = $validator->validate((string) $this->argument('dataset'));
        $this->line('records_examined: '.$result->recordsExamined);
        $this->line('records_valid: '.$result->validCount());
        $this->line('records_rejected: '.$result->rejectedCount());
        if (! $result->isValid()) {
            foreach ($result->errors() as $error) {
                $this->error($error['path'].': '.$error['message']);
            }

            return self::FAILURE;
        }
        $this->info('Field-survey package is valid.');

        return self::SUCCESS;
    }
}
