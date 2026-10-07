<?php

namespace App\Console\Commands;

use App\Services\FieldSurveys\Batch019GroundTruthCampaign;
use App\Services\FieldSurveys\FieldMissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class BiyaheBatch020Missions extends Command
{
    protected $signature = 'biyahe:batch020:missions
        {--checkpoint= : Batch 018/019 audit manifest JSON}
        {--limit=100 : Maximum claim priorities to persist}
        {--dry-run : Build and validate without writing mission rows}
        {--json : Print the bounded campaign result}';

    protected $description = 'Materialize the reviewed Batch 019 ground-truth queue as bounded Batch 020 field missions.';

    public function handle(Batch019GroundTruthCampaign $campaign, FieldMissionService $missions): int
    {
        $checkpointPath = (string) ($this->option('checkpoint') ?: storage_path('app/batch018/'.config('biyahe.routing.batch018_audit_namespace', 'batch018-network-v1').'/manifest.json'));
        if (! is_file($checkpointPath)) {
            throw new RuntimeException('A completed coverage audit manifest is required before field missions are materialized.');
        }
        $checkpoint = json_decode((string) File::get($checkpointPath), true);
        if (! is_array($checkpoint)) {
            throw new RuntimeException('The coverage audit manifest is not valid JSON.');
        }
        $payload = $campaign->build($checkpoint, min(max((int) $this->option('limit'), 1), 100));
        $result = ['campaign_version' => $payload['campaign_version'], 'claim_count' => $payload['claim_count'], 'mission_count' => $payload['mission_count']];
        if (! $this->option('dry-run')) {
            $result += $missions->syncCampaign($payload);
        } else {
            $result += ['missions' => 0, 'claims' => 0, 'dry_run' => true];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['summary' => $result, 'campaign' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result as $key => $value) {
                $this->line($key.'='.((is_scalar($value) || $value === null) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES)));
            }
            $this->info($this->option('dry-run') ? 'Batch 020 mission materialization dry run passed.' : 'Batch 020 planned field missions are ready for trusted collection.');
        }

        return self::SUCCESS;
    }
}
