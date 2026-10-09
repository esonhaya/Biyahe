<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianGraphReadinessV35;
use PHPUnit\Framework\TestCase;

final class OfflinePedestrianGraphReadinessV35Test extends TestCase
{
    public function test_reports_guard_and_cluster_evidence_but_keeps_import_and_beta_blocked(): void
    {
        $report = (new OfflinePedestrianGraphReadinessV35)->build(
            $this->doctorV34(),
            $this->resources([93256, 37928, 22316]),
            $this->cancellation(),
            $this->cluster(),
            ['format' => 'biyahe-batch035-import-state-v1', 'database_queried' => false, 'state' => 'NOT_QUERIED'],
            $this->coverage(),
        );

        $checks = array_column($report['checks'], null, 'name');
        $this->assertSame('BLOCKED', $report['status']);
        $this->assertFalse($report['postgresql_contacted']);
        $this->assertSame('PASS', $checks['resource guard cancellation']['status']);
        $this->assertSame('BLOCKED', $checks['resource stability']['status']);
        $this->assertSame('PASS', $checks['isolated cluster identity']['status']);
        $this->assertSame('BLOCKED', $checks['checkpoint integrity']['status']);
        $this->assertSame('BLOCKED', $checks['real import completeness']['status']);
        $this->assertSame('BLOCKED', $checks['private beta readiness']['status']);
        $this->assertSame('NO', $report['activation']);
    }

    public function test_rejects_cancellation_and_cluster_evidence_that_does_not_prove_ownership(): void
    {
        $cancellation = $this->cancellation();
        $cancellation['unrelated_sibling_survived'] = false;
        $cluster = $this->cluster();
        $cluster['system_identifier'] = 'wrong-cluster';

        $report = (new OfflinePedestrianGraphReadinessV35)->build(
            $this->doctorV34(),
            $this->resources([200000, 190000, 180000]),
            $cancellation,
            $cluster,
            ['format' => 'biyahe-batch035-import-state-v1', 'database_queried' => false],
            $this->coverage(),
        );
        $checks = array_column($report['checks'], null, 'name');

        $this->assertSame('BLOCKED', $checks['resource guard cancellation']['status']);
        $this->assertSame('BLOCKED', $checks['isolated cluster identity']['status']);
        $this->assertSame('BLOCKED', $report['status']);
    }

    private function doctorV34(): array
    {
        return [
            'format' => 'biyahe-doctor-v34-static-v1',
            'source_sha256' => str_repeat('a', 64),
            'graph_sha256' => str_repeat('b', 64),
            'checks' => [
                ['name' => 'source integrity', 'status' => 'PASS', 'evidence' => []],
                ['name' => 'graph integrity', 'status' => 'PASS', 'evidence' => ['nodes' => 76685, 'edges' => 78225]],
                ['name' => 'restriction readiness', 'status' => 'PASS', 'evidence' => []],
                ['name' => 'offline routing', 'status' => 'PASS', 'evidence' => []],
                ['name' => 'source-bound stop and place coverage', 'status' => 'WARN', 'evidence' => ['source_backed' => true]],
                ['name' => 'field safety evidence', 'status' => 'BLOCKED', 'evidence' => ['complete' => false]],
            ],
        ];
    }

    private function resources(array $swapFree): array
    {
        $samples = [];
        foreach ($swapFree as $swap) {
            $samples[] = ['memavailable_kib' => 700000, 'swapfree_kib' => $swap, 'diskfree_kib' => 5000000];
        }

        return [
            'format' => 'biyahe-batch035-resource-stability-v1',
            'guard' => ['minimum_memavailable_kib' => 524288, 'minimum_swapfree_kib' => 131072, 'minimum_diskfree_kib' => 1048576],
            'samples' => $samples,
        ];
    }

    private function cancellation(): array
    {
        return [
            'format' => 'biyahe-batch035-cancellation-probe-v1',
            'status' => 'PASS',
            'worker_group_verified' => true,
            'worker_and_child_stopped' => true,
            'parent_shell_survived' => true,
            'unrelated_sibling_survived' => true,
            'full_worker_log_retained' => true,
            'worker_exit' => 137,
            'worker_exit_signal' => 'SIGKILL',
        ];
    }

    private function cluster(): array
    {
        return [
            'format' => 'biyahe-batch035-isolated-cluster-evidence-v1',
            'system_identifier' => '7694494303197720087',
            'port' => 56433,
            'pg_version' => '18',
            'listen_addresses' => '127.0.0.1',
            'data_directory' => '/data/data/com.termux/files/home/.cache/biyahe/batch033-isolated-20261009/data',
            'socket_directory' => '/data/data/com.termux/files/home/.cache/biyahe/batch033-isolated-20261009/socket',
            'data_directory_matches_expected' => true,
            'socket_directory_matches_expected' => true,
            'owner_matches_current_user' => true,
            'cluster_state' => 'shut down',
            'postmaster_pid_present' => false,
            'original_cluster_touched' => false,
            'production_activation' => 'NO',
        ];
    }

    private function coverage(): array
    {
        return [
            'format' => 'biyahe-cebu-offline-coverage-v1',
            'source' => ['graph_source_sha256' => str_repeat('a', 64)],
            'snap_policy' => ['maximum_distance_meters' => 25],
            'coverage' => [
                'stops' => ['total' => 503, 'within_snap_policy' => 436, 'unreachable_or_unsnappable' => 70],
                'places' => ['total' => 37, 'within_snap_policy' => 30, 'unreachable_or_unsnappable' => 8],
                'transfers' => ['total' => 2, 'records_marked_verified' => 0],
            ],
        ];
    }
}
