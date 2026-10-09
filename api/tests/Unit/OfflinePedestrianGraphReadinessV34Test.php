<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianGraphReadinessV34;
use PHPUnit\Framework\TestCase;

final class OfflinePedestrianGraphReadinessV34Test extends TestCase
{
    public function test_low_swap_and_unqueried_checkpoint_keep_import_and_beta_blocked(): void
    {
        $graphHash = str_repeat('a', 64);
        $sourceHash = str_repeat('b', 64);
        $report = (new OfflinePedestrianGraphReadinessV34)->build(
            $this->doctorV33($graphHash, $sourceHash),
            [
                'format' => 'biyahe-batch033-staging-evidence-v1',
                'identity' => ['graph_sha256' => $graphHash, 'source_sha256' => $sourceHash, 'existing_cluster_touched' => false],
                'production_activation' => 'NO',
                'import' => ['status' => 'BLOCKED', 'nodes' => 30000, 'edges' => 0, 'state' => 'INCOMPLETE'],
            ],
            [
                'format' => 'biyahe-termux-resource-diagnostic-v1',
                'guard' => ['minimum_memavailable_kib' => 524288, 'minimum_swapfree_kib' => 131072],
                'latest_sample' => ['memavailable_kib' => 700000, 'swapfree_kib' => 14000],
            ],
        );
        $statuses = array_column($report['checks'], 'status', 'name');

        $this->assertSame('BLOCKED', $report['status']);
        $this->assertSame('PASS', $statuses['source integrity']);
        $this->assertSame('BLOCKED', $statuses['staging resource readiness']);
        $this->assertSame('BLOCKED', $statuses['checkpoint validity']);
        $this->assertSame('BLOCKED', $statuses['route parity']);
        $this->assertSame('BLOCKED', $statuses['private beta readiness']);
        $this->assertFalse($report['postgresql_contacted']);
        $this->assertSame('NO', $report['activation']);
    }

    public function test_unbound_evidence_hashes_fail_closed(): void
    {
        $report = (new OfflinePedestrianGraphReadinessV34)->build(
            $this->doctorV33(str_repeat('a', 64), str_repeat('b', 64)),
            ['format' => 'wrong-format', 'identity' => ['graph_sha256' => str_repeat('a', 64), 'source_sha256' => str_repeat('b', 64), 'existing_cluster_touched' => false]],
            [],
        );
        $statuses = array_column($report['checks'], 'status', 'name');

        $this->assertSame('FAIL', $statuses['source integrity']);
        $this->assertSame('FAIL', $statuses['graph integrity']);
        $this->assertSame('FAIL', $report['status']);
    }

    private function doctorV33(string $graphHash, string $sourceHash): array
    {
        return [
            'format' => 'biyahe-doctor-v33-static-v1',
            'graph_sha256' => $graphHash,
            'source_sha256' => $sourceHash,
            'checks' => [
                ['name' => 'source integrity', 'status' => 'PASS', 'evidence' => []],
                ['name' => 'graph integrity', 'status' => 'PASS', 'evidence' => ['nodes' => 76685, 'edges' => 78225]],
                ['name' => 'restriction readiness', 'status' => 'PASS', 'evidence' => ['inventory_complete' => true]],
                ['name' => 'offline route readiness', 'status' => 'PASS', 'evidence' => ['cases' => 10]],
                ['name' => 'real import completeness', 'status' => 'BLOCKED', 'evidence' => ['nodes' => 30000, 'edges' => 0]],
                ['name' => 'real rollback', 'status' => 'PASS', 'evidence' => ['controlled_failure' => true]],
                ['name' => 'real idempotency', 'status' => 'BLOCKED', 'evidence' => ['status' => 'NOT_RUN']],
                ['name' => 'conflicting artifact rejection', 'status' => 'BLOCKED', 'evidence' => ['status' => 'NOT_RUN']],
                ['name' => 'offline/staged route parity', 'status' => 'BLOCKED', 'evidence' => ['status' => 'NOT_RUN']],
                ['name' => 'stop coverage limitations', 'status' => 'WARN', 'evidence' => ['unsnappable' => 67]],
                ['name' => 'transfer verification', 'status' => 'WARN', 'evidence' => ['verified' => 0]],
                ['name' => 'field safety evidence', 'status' => 'BLOCKED', 'evidence' => ['complete' => false]],
            ],
        ];
    }
}
