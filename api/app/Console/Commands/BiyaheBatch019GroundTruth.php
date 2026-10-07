<?php

namespace App\Console\Commands;

use App\Services\FieldSurveys\Batch019GroundTruthCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class BiyaheBatch019GroundTruth extends Command
{
    protected $signature = 'biyahe:batch019:ground-truth
        {--checkpoint= : Batch 018 manifest path}
        {--namespace=batch019-ground-truth-v1 : Ignored resumable runtime namespace}
        {--limit=100 : Maximum claim priorities}
        {--resume : Reuse a compatible generated claim queue}
        {--fresh : Replace the generated claim queue}
        {--output= : Optional export path}
        {--json : Emit machine-readable output}';

    protected $description = 'Build a resumable Batch 019 claim queue and bundled field-ground-truth missions.';

    public function handle(Batch019GroundTruthCampaign $campaign): int
    {
        $namespace = (string) $this->option('namespace');
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $namespace) !== 1) {
            $this->error('Invalid runtime namespace.');

            return self::INVALID;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('limit must be between 1 and 100.');

            return self::INVALID;
        }

        $checkpointPath = (string) ($this->option('checkpoint') ?: storage_path('app/batch018/'.config('biyahe.routing.batch018_audit_namespace', 'batch018-network-v1').'/manifest.json'));
        $runtimeDirectory = storage_path('app/batch019/'.$namespace);
        $queuePath = $runtimeDirectory.'/claim-queue.json';
        if ($this->option('fresh') && is_dir($runtimeDirectory)) {
            File::deleteDirectory($runtimeDirectory);
        }
        $checkpoint = is_file($checkpointPath) ? json_decode((string) file_get_contents($checkpointPath), true) : null;
        if (! is_array($checkpoint)) {
            $this->error('Batch 018 checkpoint is missing or malformed.');

            return self::FAILURE;
        }

        $payload = null;
        if ($this->option('resume') && is_file($queuePath)) {
            $payload = json_decode((string) file_get_contents($queuePath), true);
            if (! is_array($payload)
                || ($payload['campaign_version'] ?? null) !== Batch019GroundTruthCampaign::VERSION
                || ($payload['network_fingerprint'] ?? null) !== ($checkpoint['network_fingerprint'] ?? null)) {
                $this->error('Existing claim queue is incompatible; use --fresh or a new namespace.');

                return self::FAILURE;
            }
        }
        if ($payload === null) {
            $payload = $campaign->build($checkpoint, $limit);
            $payload['checkpoint'] = [
                'path' => $checkpointPath,
                'audit_version' => $checkpoint['audit_version'] ?? null,
            ];
            $campaign->writeAtomic($queuePath, $payload);
        }
        if ($this->option('output')) {
            $campaign->writeAtomic((string) $this->option('output'), $payload);
        }

        $summary = [
            'campaign_version' => $payload['campaign_version'],
            'claims' => $payload['claim_count'],
            'missions' => $payload['mission_count'],
            'queue_path' => $queuePath,
            'automatic_promotions' => $payload['promotion_gate']['automatic_promotions'] ?? 0,
            'research_status' => collect($payload['claims'] ?? [])->countBy('research_status')->all(),
        ];
        if ($this->option('json')) {
            $this->line(json_encode([...$payload, 'summary' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info('BIYAHE BATCH 019 GROUND-TRUTH CAMPAIGN');
        foreach ($summary as $key => $value) {
            $this->line($key.': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value));
        }
        foreach ($payload['missions'] ?? [] as $mission) {
            $this->line($mission['id'].': '.count($mission['claim_ids']).' claims — '.$mission['title']);
        }

        return self::SUCCESS;
    }
}
