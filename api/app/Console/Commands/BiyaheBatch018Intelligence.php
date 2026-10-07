<?php

namespace App\Console\Commands;

use App\Services\Evidence\NetworkIntelligenceAudit;
use Illuminate\Console\Command;

final class BiyaheBatch018Intelligence extends Command
{
    protected $signature = 'biyahe:batch018:intelligence
        {--checkpoint= : Path to a Batch 018 manifest.json}
        {--limit=30 : Maximum claim priorities}
        {--json : Emit machine-readable output}';

    protected $description = 'Build claim-level evidence, boarding, pedestrian-gap, and data-quality priorities from bounded audit results.';

    public function handle(NetworkIntelligenceAudit $intelligence): int
    {
        $path = (string) ($this->option('checkpoint') ?: storage_path('app/batch018/'.config('biyahe.routing.batch018_audit_namespace', 'batch018-network-v1').'/manifest.json'));
        $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        if (! is_array($payload)) {
            $this->error('Checkpoint JSON is malformed.');

            return self::FAILURE;
        }

        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $report = $intelligence->analyze($results, (int) $this->option('limit'));
        $report['checkpoint'] = [
            'path' => $path,
            'audit_version' => $payload['audit_version'] ?? null,
            'network_fingerprint' => $payload['network_fingerprint'] ?? null,
            'pairs_complete' => count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'COMPLETE')),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('BIYAHE BATCH 018 NETWORK INTELLIGENCE');
        $this->line('checkpoint: '.$path);
        foreach ([
            'evidence_priorities.top_service',
            'evidence_priorities.top_sequence',
            'evidence_priorities.top_boarding',
            'evidence_priorities.top_transfers',
            'evidence_priorities.field_survey_tasks',
            'pedestrian_gaps',
            'data_quality',
        ] as $section) {
            $value = $report;
            foreach (explode('.', $section) as $part) {
                $value = is_array($value) ? ($value[$part] ?? null) : null;
            }
            $this->line($section.': '.json_encode($value, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
