<?php

namespace App\Console\Commands;

use App\Services\FieldSurveys\SurveyImportConflict;
use App\Services\FieldSurveys\SurveyImporter;
use App\Services\FieldSurveys\SurveyImportValidator;
use Illuminate\Console\Command;
use Throwable;

final class ImportSurveyDataset extends Command
{
    protected $signature = 'biyahe:surveys:import {dataset : Version 1 offline survey JSON file} {--dry-run : Validate without importing}';

    protected $description = 'Validate and safely import an offline Biyahe field-survey package.';

    public function handle(SurveyImportValidator $validator, SurveyImporter $importer): int
    {
        $validation = $validator->validate((string) $this->argument('dataset'));
        if (! $validation->isValid()) {
            $this->line('records_examined: '.$validation->recordsExamined);
            $this->line('records_valid: '.$validation->validCount());
            $this->line('records_rejected: '.$validation->rejectedCount());
            foreach ($validation->errors() as $error) {
                $this->error($error['path'].': '.$error['message']);
            }

            return self::FAILURE;
        }
        try {
            $summary = $this->option('dry-run')
                ? $importer->dryRun($validation)
                : $importer->import($validation);
        } catch (SurveyImportConflict $exception) {
            $this->error('Import rolled back: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Import failed and was rolled back. Review the server log for the internal error.');

            return self::FAILURE;
        }

        foreach ($summary->counts() as $type => $counts) {
            $this->line($type.': '.json_encode($counts, JSON_UNESCAPED_SLASHES));
        }
        $allCounts = $summary->counts();
        $examined = array_sum(array_column($allCounts, 'examined'));
        $rejected = array_sum(array_column($allCounts, 'rejected'));
        $this->line('records_examined: '.$examined);
        $this->line('records_valid: '.max(0, $examined - $rejected));
        $this->line('records_rejected: '.$rejected);
        $this->info($this->option('dry-run')
            ? 'Dry run passed after database conflict checks; no survey records were persisted.'
            : 'Field-survey package imported atomically.');

        return self::SUCCESS;
    }
}
