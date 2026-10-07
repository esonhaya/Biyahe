<?php

namespace App\Console\Commands;

use App\Services\DeploymentReadinessService;
use Illuminate\Console\Command;

final class BiyaheReleaseCheck extends Command
{
    protected $signature = 'biyahe:release-check {--json : Emit machine-readable output} {--allow-non-postgis : Run structural checks without requiring PostgreSQL/PostGIS}';

    protected $description = 'Check bounded production deployment prerequisites without deploying.';

    public function handle(DeploymentReadinessService $readiness): int
    {
        $result = $readiness->check(! $this->option('allow-non-postgis'));
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('status='.$result['status']);
            foreach ($result['checks'] as $name => $check) {
                $this->line($name.'='.($check['status'] ?? 'UNKNOWN'));
            }
        }

        return $result['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }
}
