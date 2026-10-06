<?php

namespace App\Console\Commands;

use App\Services\FieldSurveys\FieldSurveyPlanner;
use App\Services\FieldSurveys\FieldSurveyReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class FieldSurveyReportCommand extends Command
{
    protected $signature = 'biyahe:surveys:report {--json : Emit machine-readable JSON}';

    protected $description = 'Report non-synthetic field-survey evidence and review status.';

    public function handle(FieldSurveyReport $report, FieldSurveyPlanner $planner): int
    {
        if (! Schema::hasTable('transport_survey_sessions')) {
            $this->error('Field-survey tables are missing. Run the pending field-survey migration first.');

            return self::FAILURE;
        }

        $plan = $planner->plan();
        $data = $report->report($plan['priority_analysis']);
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        foreach ($data as $key => $value) {
            $this->line($key.': '.(is_array($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES)
                : $value));
        }

        return self::SUCCESS;
    }
}
