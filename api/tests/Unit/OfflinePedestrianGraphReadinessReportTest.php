<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianGraphReadinessReport;
use Tests\TestCase;

final class OfflinePedestrianGraphReadinessReportTest extends TestCase
{
    public function test_real_data_without_staging_or_field_evidence_is_blocked_without_database_contact(): void
    {
        $hash = str_repeat('a', 64);
        $graph = [
            'artifact_ready' => true,
            'routing_ready' => true,
            'nodes' => 10,
            'edges' => 9,
            'components' => 1,
            'graph_fingerprint' => str_repeat('b', 64),
            'restriction_inventory_complete' => true,
            'restriction_source_relation_count' => 0,
            'restriction_counts' => ['vehicle_only' => 0],
            'restriction_source_classification_counts' => ['total_turn_restrictions' => 0],
            'restriction_source_omitted_count' => 0,
            'restriction_audit_sha256' => str_repeat('e', 64),
            'routing_capabilities' => ['routing_ready' => true],
        ];
        $coverage = [
            'format' => 'biyahe-cebu-offline-coverage-v1',
            'complete' => true,
            'source' => [
                'graph_source_sha256' => $hash,
                'graph_fingerprint' => str_repeat('b', 64),
                'transport_backup_sha256' => str_repeat('c', 64),
            ],
            'graph_validation' => ['artifact_sha256' => str_repeat('d', 64)],
            'coverage' => [
                'stops' => ['total' => 3, 'within_snap_policy' => 2],
                'places' => ['total' => 2, 'within_snap_policy' => 2],
                'transfers' => ['total' => 1, 'graph_route_connected' => 1, 'records_marked_verified' => 0],
            ],
            'journey_corpus' => ['case_count' => 5, 'routes_found' => 2, 'routes_unavailable' => 3],
            'quality_evidence' => ['counts' => ['long_edges_over_250m' => 0, 'suspicious_long_edges' => 0]],
            'field_verification' => ['complete' => false, 'gaps' => ['not field checked']],
        ];
        $staging = [
            'status' => 'BLOCKED',
            'source_sha256' => $hash,
            'graph_sha256' => str_repeat('d', 64),
            'graph_fingerprint' => str_repeat('b', 64),
            'backup_sha256' => str_repeat('c', 64),
            'isolated_cluster' => true,
            'existing_cluster_touched' => false,
            'imported_nodes' => 0,
            'imported_edges' => 0,
            'activation' => 'NO',
            'reason' => 'resource guard stopped import',
            'import' => [
                'transactional_import_result' => 'NOT_VERIFIED_FOR_REAL_GRAPH',
                'explicit_rollback_test' => 'NOT_RUN',
                'idempotency_test' => 'NOT_RUN',
            ],
        ];

        $report = (new OfflinePedestrianGraphReadinessReport)->build(str_repeat('d', 64), $hash, $graph, $coverage, $staging);
        $checks = array_column($report['checks'], null, 'name');

        $this->assertSame('BLOCKED', $report['status']);
        $this->assertFalse($report['postgresql_contacted']);
        $this->assertSame('BLOCKED', $checks['isolated PostgreSQL staging']['status']);
        $this->assertSame('BLOCKED', $checks['private beta readiness']['status']);
        $this->assertSame('BLOCKED', $checks['production activation']['status']);
        $this->assertSame('NOT_VERIFIED_FOR_REAL_GRAPH', $checks['importer real-artifact compatibility']['evidence']['transactional']);
        $this->assertSame(['total_turn_restrictions' => 0], $checks['restriction inventory']['evidence']['source_classification_counts']);
    }

    public function test_coverage_identity_mismatch_fails_closed(): void
    {
        $graphHash = str_repeat('a', 64);
        $sourceHash = str_repeat('b', 64);
        $report = (new OfflinePedestrianGraphReadinessReport)->build($graphHash, $sourceHash, [
            'artifact_ready' => true,
            'routing_ready' => true,
            'graph_fingerprint' => str_repeat('c', 64),
        ], [
            'format' => 'biyahe-cebu-offline-coverage-v1',
            'complete' => true,
            'source' => [
                'graph_source_sha256' => $sourceHash,
                'graph_fingerprint' => str_repeat('c', 64),
                'transport_backup_sha256' => str_repeat('d', 64),
            ],
            'graph_validation' => ['artifact_sha256' => str_repeat('e', 64)],
        ], [
            'source_sha256' => $sourceHash,
            'backup_sha256' => str_repeat('d', 64),
            'activation' => 'NO',
        ]);
        $checks = array_column($report['checks'], null, 'name');

        $this->assertSame('FAIL', $checks['coverage report identity']['status']);
        $this->assertSame('FAIL', $report['status']);
    }

    public function test_missing_activation_boundary_and_empty_coverage_do_not_pass(): void
    {
        $graphHash = str_repeat('d', 64);
        $sourceHash = str_repeat('a', 64);
        $fingerprint = str_repeat('b', 64);
        $backupHash = str_repeat('c', 64);
        $graph = [
            'artifact_ready' => true,
            'routing_ready' => true,
            'nodes' => 10,
            'edges' => 9,
            'components' => 1,
            'graph_fingerprint' => $fingerprint,
            'restriction_inventory_complete' => true,
        ];
        $coverage = [
            'format' => 'biyahe-cebu-offline-coverage-v1',
            'complete' => true,
            'source' => [
                'graph_source_sha256' => $sourceHash,
                'graph_fingerprint' => $fingerprint,
                'transport_backup_sha256' => $backupHash,
            ],
            'graph_validation' => ['artifact_sha256' => $graphHash],
            'coverage' => [
                'stops' => ['total' => 1, 'within_snap_policy' => 1],
                'places' => ['total' => 1, 'within_snap_policy' => 1],
                'transfers' => ['total' => 1, 'graph_route_connected' => 1, 'records_marked_verified' => 1],
            ],
            'journey_corpus' => ['case_count' => 2, 'routes_found' => 1, 'routes_unavailable' => 1],
            'quality_evidence' => ['counts' => [
                'long_edges_over_250m' => 0,
                'suspicious_long_edges' => 0,
                'duplicate_way_segment_identities' => 0,
                'edges_touching_blocked_nodes' => 0,
                'boundary_nodes' => 0,
                'crossing_tagged_nodes' => 0,
                'clipped_ways' => 0,
                'outside_scope_required_nodes' => 0,
                'walkable_components' => 1,
            ]],
        ];
        $staging = [
            'status' => 'PASS',
            'source_sha256' => $sourceHash,
            'graph_sha256' => $graphHash,
            'graph_fingerprint' => $fingerprint,
            'backup_sha256' => $backupHash,
            'isolated_cluster' => true,
            'existing_cluster_touched' => false,
            'imported_nodes' => 10,
            'imported_edges' => 9,
            'backup_restore' => ['status' => 'PASS'],
            'import' => [
                'status' => 'PASS',
                'transactional_import_result' => 'PASS',
                'explicit_rollback_test' => 'PASS',
                'idempotency_test' => 'PASS',
                'no_clobber' => true,
                'inactive_only' => true,
            ],
            // Deliberately omit activation: absence is not proof of non-activation.
        ];

        $report = (new OfflinePedestrianGraphReadinessReport)->build($graphHash, $sourceHash, $graph, $coverage, $staging);
        $checks = array_column($report['checks'], null, 'name');

        $this->assertSame('BLOCKED', $checks['isolated PostgreSQL staging']['status']);
        $this->assertSame('BLOCKED', $report['status']);

        $coverage['coverage']['stops'] = ['total' => 0, 'within_snap_policy' => 0];
        $staging['activation'] = 'NO';
        $emptyCoverageReport = (new OfflinePedestrianGraphReadinessReport)->build($graphHash, $sourceHash, $graph, $coverage, $staging);
        $emptyChecks = array_column($emptyCoverageReport['checks'], null, 'name');
        $this->assertSame('BLOCKED', $emptyChecks['stop coverage']['status']);
    }
}
