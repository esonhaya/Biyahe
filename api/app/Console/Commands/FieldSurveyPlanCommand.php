<?php

namespace App\Console\Commands;

use App\Services\FieldSurveys\FieldSurveyPlanner;
use Illuminate\Console\Command;

final class FieldSurveyPlanCommand extends Command
{
    protected $signature = 'biyahe:surveys:plan {--pairs=6 : Bounded origin/destination pairs for impact analysis} {--max-transfers=2 : Transfer depth for the impact analysis} {--json : Emit machine-readable survey tasks}';

    protected $description = 'Build impact-ranked field survey packages from current evidence priorities.';

    public function handle(FieldSurveyPlanner $planner): int
    {
        $pairs = filter_var($this->option('pairs'), FILTER_VALIDATE_INT);
        $transfers = filter_var($this->option('max-transfers'), FILTER_VALIDATE_INT);
        if ($pairs === false || $pairs < 1 || $pairs > (int) config('biyahe.evidence.priority.max_pairs', 60)
            || $transfers === false || $transfers < 0 || $transfers > (int) config('biyahe.routing.public_max_transfers', 2)) {
            $this->error('pairs or max-transfers is outside its configured safe range.');

            return self::INVALID;
        }

        $plan = $planner->plan($pairs, $transfers);
        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->line('SURVEY PRIORITIES ('.$plan['priority_analysis']['pairs_examined'].' O/D pairs examined)');
        foreach (array_slice($plan['priority_analysis']['items'], 0, 15) as $item) {
            $this->line(sprintf(
                '%d. %s %s fact=%s journeys=%d pairs=%d — %s',
                $item['rank'],
                $item['type'],
                $item['stable_id'] ?? 'unknown-record',
                $item['fact'] ?? 'all',
                $item['affected_journeys'],
                $item['affected_od_pairs_count'],
                $item['reason'],
            ));
        }
        $this->line(sprintf(
            'analysis_elapsed_ms=%s database_queries=%d planner_runs=%d cache_hits=%d',
            $plan['performance']['elapsed_milliseconds'],
            $plan['performance']['database_queries'],
            $plan['performance']['planner_runs'],
            $plan['performance']['result_cache_hits'],
        ));
        foreach ($plan['survey_packages'] as $package) {
            $this->newLine();
            $this->info($package['title']);
            $this->line((string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->comment($plan['privacy']);

        return self::SUCCESS;
    }
}
