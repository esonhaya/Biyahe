<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianGraphReadinessV33;
use PHPUnit\Framework\TestCase;

final class OfflinePedestrianGraphReadinessV33Test extends TestCase
{
    public function test_missing_real_stage_and_parity_evidence_remain_blocked(): void
    {
        $graphHash = str_repeat('a', 64);
        $sourceHash = str_repeat('b', 64);
        $baseline = $this->baseline($graphHash, $sourceHash);
        $staging = [
            'identity' => ['graph_sha256' => $graphHash, 'source_sha256' => $sourceHash, 'existing_cluster_touched' => false],
            'resource_preflight' => ['status' => 'BLOCKED', 'reason' => 'swap below floor'],
            'import' => ['status' => 'BLOCKED', 'nodes' => 0, 'edges' => 0, 'state' => 'NOT_STARTED'],
            'activation' => 'NO',
        ];

        $report = (new OfflinePedestrianGraphReadinessV33)->build($baseline, $staging, null);
        $statuses = array_column($report['checks'], 'status', 'name');

        $this->assertSame('BLOCKED', $report['status']);
        $this->assertSame('PASS', $statuses['source integrity']);
        $this->assertSame('PASS', $statuses['offline route readiness']);
        $this->assertSame('BLOCKED', $statuses['real import completeness']);
        $this->assertSame('BLOCKED', $statuses['real rollback']);
        $this->assertSame('BLOCKED', $statuses['offline/staged route parity']);
        $this->assertSame('BLOCKED', $statuses['private beta readiness']);
        $this->assertFalse($report['postgresql_contacted']);
    }

    private function baseline(string $graphHash, string $sourceHash): array
    {
        return [
            'format' => 'biyahe-doctor-v32-static-v1',
            'graph_sha256' => $graphHash,
            'source_sha256' => $sourceHash,
            'checks' => [
                ['name' => 'source verification', 'status' => 'PASS', 'evidence' => ['source_sha256' => $sourceHash]],
                ['name' => 'graph structural integrity', 'status' => 'PASS', 'evidence' => ['graph_sha256' => $graphHash, 'nodes' => 76685, 'edges' => 78225]],
                ['name' => 'snapshot routing capability', 'status' => 'PASS', 'evidence' => ['routing_ready' => true]],
                ['name' => 'real journey corpus', 'status' => 'PASS', 'evidence' => ['cases' => 10, 'routes_found' => 9, 'routes_unavailable' => 1]],
                ['name' => 'stop coverage', 'status' => 'WARN', 'evidence' => ['total' => 503, 'within_snap_policy' => 436, 'unreachable_or_unsnappable' => 67]],
                ['name' => 'transfer coverage and trust', 'status' => 'WARN', 'evidence' => ['total' => 2, 'records_marked_verified' => 0, 'graph_route_connected' => 2]],
                ['name' => 'field verification', 'status' => 'WARN', 'evidence' => ['complete' => false]],
            ],
        ];
    }
}
