<?php

namespace App\Services\Routing;

/** Computes honest static readiness from already verified evidence. */
final class OfflinePedestrianGraphReadinessReport
{
    /** @return array<string, mixed> */
    public function build(string $graphSha256, string $sourceSha256, array $graphValidation, array $coverage, array $staging): array
    {
        $sourceMatches = hash_equals($sourceSha256, strtolower((string) ($coverage['source']['graph_source_sha256'] ?? '')))
            && hash_equals($sourceSha256, strtolower((string) ($staging['source_sha256'] ?? '')));
        $graphMatches = hash_equals($graphSha256, strtolower((string) ($coverage['graph_validation']['artifact_sha256'] ?? '')))
            && hash_equals(strtolower((string) ($graphValidation['graph_fingerprint'] ?? '')), strtolower((string) ($coverage['source']['graph_fingerprint'] ?? '')));
        $coverageComplete = ($coverage['complete'] ?? false) === true
            && ($coverage['format'] ?? null) === 'biyahe-cebu-offline-coverage-v1';
        $coverageBackupHash = strtolower((string) ($coverage['source']['transport_backup_sha256'] ?? ''));
        $stagingBackupHash = strtolower((string) ($staging['backup_sha256'] ?? ''));
        $backupMatches = preg_match('/^[a-f0-9]{64}$/', $coverageBackupHash) === 1
            && preg_match('/^[a-f0-9]{64}$/', $stagingBackupHash) === 1
            && hash_equals($coverageBackupHash, $stagingBackupHash);
        $stagingIdentityMatches = $sourceMatches
            && $backupMatches
            && hash_equals($graphSha256, strtolower((string) ($staging['graph_sha256'] ?? '')))
            && hash_equals(strtolower((string) ($graphValidation['graph_fingerprint'] ?? '')), strtolower((string) ($staging['graph_fingerprint'] ?? '')))
            && ($staging['isolated_cluster'] ?? false) === true
            && ($staging['existing_cluster_touched'] ?? true) === false;
        $stagingImport = is_array($staging['import'] ?? null) ? $staging['import'] : [];
        $restoreEvidence = is_array($staging['backup_restore'] ?? null) ? $staging['backup_restore'] : [];
        $stagingComplete = $stagingIdentityMatches
            && ($staging['status'] ?? null) === 'PASS'
            && ($restoreEvidence['status'] ?? null) === 'PASS'
            && ($stagingImport['status'] ?? null) === 'PASS'
            && ($stagingImport['transactional_import_result'] ?? null) === 'PASS'
            && ($stagingImport['explicit_rollback_test'] ?? null) === 'PASS'
            && ($stagingImport['idempotency_test'] ?? null) === 'PASS'
            && ($stagingImport['no_clobber'] ?? false) === true
            && ($stagingImport['inactive_only'] ?? false) === true
            && (int) ($staging['imported_nodes'] ?? 0) === (int) ($graphValidation['nodes'] ?? -1)
            && (int) ($staging['imported_edges'] ?? 0) === (int) ($graphValidation['edges'] ?? -1)
            && ($staging['activation'] ?? null) === 'NO';
        $journeyCorpus = $coverage['journey_corpus'] ?? [];
        $routesFound = (int) ($journeyCorpus['routes_found'] ?? 0);
        $routesUnavailable = (int) ($journeyCorpus['routes_unavailable'] ?? 0);
        $coverageSummary = $coverage['coverage'] ?? [];
        $quality = $coverage['quality_evidence'] ?? [];
        $qualityCounts = $quality['counts'] ?? [];
        $suspiciousLongEdges = (int) ($qualityCounts['suspicious_long_edges'] ?? -1);
        $longEdges = (int) ($qualityCounts['long_edges_over_250m'] ?? -1);
        $unsafeTopologySignals = (int) ($qualityCounts['duplicate_way_segment_identities'] ?? -1)
            + (int) ($qualityCounts['edges_touching_blocked_nodes'] ?? -1);
        $qualityComplete = $longEdges >= 0
            && $suspiciousLongEdges >= 0
            && isset(
                $qualityCounts['duplicate_way_segment_identities'],
                $qualityCounts['edges_touching_blocked_nodes'],
                $qualityCounts['boundary_nodes'],
                $qualityCounts['crossing_tagged_nodes'],
                $qualityCounts['clipped_ways'],
                $qualityCounts['outside_scope_required_nodes'],
                $qualityCounts['walkable_components'],
            );
        $qualityStatus = ! $qualityComplete ? 'BLOCKED'
            : ($suspiciousLongEdges > 0 || $unsafeTopologySignals > 0 ? 'FAIL'
                : ($longEdges > 0 || (int) ($qualityCounts['walkable_components'] ?? 0) > 1 || (int) $qualityCounts['boundary_nodes'] > 0 ? 'WARN' : 'PASS'));
        $fieldVerified = ($coverage['field_verification']['complete'] ?? false) === true;

        $checks = [
            ['name' => 'source verification', 'status' => $sourceMatches ? 'PASS' : 'FAIL', 'evidence' => ['source_sha256' => $sourceSha256]],
            ['name' => 'graph structural integrity', 'status' => ($graphValidation['artifact_ready'] ?? false) ? 'PASS' : 'FAIL', 'evidence' => ['graph_sha256' => $graphSha256, 'nodes' => $graphValidation['nodes'] ?? 0, 'edges' => $graphValidation['edges'] ?? 0, 'components' => $graphValidation['components'] ?? 0]],
            ['name' => 'restriction inventory', 'status' => ($graphValidation['restriction_inventory_complete'] ?? false) ? 'PASS' : 'BLOCKED', 'evidence' => [
                'source_relation_count' => $graphValidation['restriction_source_relation_count'] ?? 0,
                'source_omitted_relation_count' => $graphValidation['restriction_source_omitted_count'] ?? null,
                'source_classification_counts' => $graphValidation['restriction_source_classification_counts'] ?? [],
                'stored_relation_counts' => $graphValidation['restriction_counts'] ?? [],
                'audit_sha256' => $graphValidation['restriction_audit_sha256'] ?? null,
                'unsupported_applicable' => $graphValidation['unsupported_applicable_restrictions'] ?? 0,
                'ambiguous' => $graphValidation['ambiguous_restrictions'] ?? 0,
            ]],
            ['name' => 'snapshot routing capability', 'status' => ($graphValidation['routing_ready'] ?? false) ? 'PASS' : 'BLOCKED', 'evidence' => $graphValidation['routing_capabilities'] ?? []],
            ['name' => 'coverage report identity', 'status' => $coverageComplete && $sourceMatches && $backupMatches && $graphMatches ? 'PASS' : 'FAIL', 'evidence' => ['records_sha256' => $coverage['source']['transport_records_sha256'] ?? null, 'backup_sha256' => $coverage['source']['transport_backup_sha256'] ?? null]],
            ['name' => 'stop coverage', 'status' => $this->coverageStatus($coverageSummary['stops'] ?? []), 'evidence' => $this->coverageCounts($coverageSummary['stops'] ?? [])],
            ['name' => 'place coverage', 'status' => $this->coverageStatus($coverageSummary['places'] ?? []), 'evidence' => $this->coverageCounts($coverageSummary['places'] ?? [])],
            ['name' => 'transfer coverage and trust', 'status' => $this->transferStatus($coverageSummary['transfers'] ?? []), 'evidence' => $this->transferCounts($coverageSummary['transfers'] ?? [])],
            ['name' => 'real journey corpus', 'status' => $routesFound > 0 && $routesUnavailable > 0 ? 'PASS' : 'WARN', 'evidence' => ['cases' => (int) ($journeyCorpus['case_count'] ?? 0), 'routes_found' => $routesFound, 'routes_unavailable' => $routesUnavailable, 'latency_milliseconds' => $journeyCorpus['latency_milliseconds'] ?? null]],
            ['name' => 'graph quality review', 'status' => $qualityStatus, 'evidence' => [
                'long_edges_over_250m' => $longEdges,
                'suspicious_long_edges' => $suspiciousLongEdges,
                'duplicate_way_segment_identities' => $qualityCounts['duplicate_way_segment_identities'] ?? null,
                'edges_touching_blocked_nodes' => $qualityCounts['edges_touching_blocked_nodes'] ?? null,
                'boundary_nodes' => $qualityCounts['boundary_nodes'] ?? null,
                'crossing_tagged_nodes' => $qualityCounts['crossing_tagged_nodes'] ?? null,
                'clipped_ways' => $qualityCounts['clipped_ways'] ?? null,
                'outside_scope_required_nodes' => $qualityCounts['outside_scope_required_nodes'] ?? null,
                'walkable_components' => $qualityCounts['walkable_components'] ?? null,
                'field_review_required' => true,
            ]],
            ['name' => 'staging evidence identity', 'status' => $stagingIdentityMatches ? 'PASS' : 'FAIL', 'evidence' => ['source_matches' => $sourceMatches, 'graph_matches' => hash_equals($graphSha256, strtolower((string) ($staging['graph_sha256'] ?? ''))), 'fingerprint_matches' => hash_equals(strtolower((string) ($graphValidation['graph_fingerprint'] ?? '')), strtolower((string) ($staging['graph_fingerprint'] ?? ''))), 'backup_matches' => $backupMatches, 'isolated_cluster' => $staging['isolated_cluster'] ?? false, 'existing_cluster_touched' => $staging['existing_cluster_touched'] ?? null]],
            ['name' => 'isolated PostgreSQL staging', 'status' => $stagingComplete ? 'PASS' : 'BLOCKED', 'evidence' => ['status' => $staging['status'] ?? 'UNKNOWN', 'imported_nodes' => (int) ($staging['imported_nodes'] ?? 0), 'imported_edges' => (int) ($staging['imported_edges'] ?? 0), 'reason' => $staging['reason'] ?? null]],
            ['name' => 'importer real-artifact compatibility', 'status' => $stagingComplete ? 'PASS' : 'BLOCKED', 'evidence' => [
                'real_artifact_import' => $staging['status'] ?? 'NOT_COMPLETED',
                'transactional' => $stagingImport['transactional_import_result'] ?? $staging['transactional'] ?? 'NOT_VERIFIED',
                'explicit_rollback_test' => $stagingImport['explicit_rollback_test'] ?? 'NOT_RUN',
                'idempotency_test' => $stagingImport['idempotency_test'] ?? 'NOT_RUN',
                'no_clobber' => $stagingImport['no_clobber'] ?? false,
                'inactive_only' => true,
            ]],
            ['name' => 'offline/staged route parity', 'status' => ($staging['offline_staged_parity'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED', 'evidence' => ['status' => $staging['offline_staged_parity'] ?? 'NOT_RUN']],
            ['name' => 'commuter-facing failure states', 'status' => ($coverage['failure_response_contract']['honest_unavailable_state'] ?? false) === true ? 'PASS' : 'WARN', 'evidence' => $coverage['failure_response_contract'] ?? ['status' => 'not_included_in_coverage_run']],
            ['name' => 'field verification', 'status' => $fieldVerified ? 'PASS' : 'WARN', 'evidence' => ['complete' => $fieldVerified, 'gaps' => $coverage['field_verification']['gaps'] ?? []]],
            ['name' => 'production activation', 'status' => 'BLOCKED', 'evidence' => ['activation' => 'NO', 'reason' => 'Batch032 does not activate pedestrian routing in production.']],
        ];
        $privateBeta = $stagingComplete && $routesFound > 0 && $fieldVerified && $suspiciousLongEdges === 0;
        $checks[] = [
            'name' => 'private beta readiness',
            'status' => $privateBeta ? 'PASS' : 'BLOCKED',
            'evidence' => ['staging_complete' => $stagingComplete, 'real_routes_found' => $routesFound, 'field_verification_complete' => $fieldVerified, 'suspicious_long_edges' => $suspiciousLongEdges],
        ];
        $statuses = array_column($checks, 'status');
        $status = in_array('FAIL', $statuses, true) ? 'FAIL' : (in_array('BLOCKED', $statuses, true) ? 'BLOCKED' : (in_array('WARN', $statuses, true) ? 'WARN' : 'PASS'));

        return [
            'format' => 'biyahe-doctor-v32-static-v1',
            'status' => $status,
            'postgresql_contacted' => false,
            'checks' => $checks,
            'source_sha256' => $sourceSha256,
            'graph_sha256' => $graphSha256,
            'graph_fingerprint' => $coverage['source']['graph_fingerprint'] ?? null,
        ];
    }

    private function coverageStatus(array $coverage): string
    {
        if (! isset($coverage['total'], $coverage['within_snap_policy'])) {
            return 'BLOCKED';
        }
        if ((int) $coverage['total'] <= 0) {
            return 'BLOCKED';
        }
        if ((int) $coverage['within_snap_policy'] < 0 || (int) $coverage['within_snap_policy'] > (int) $coverage['total']) {
            return 'FAIL';
        }

        return (int) $coverage['within_snap_policy'] === (int) $coverage['total'] ? 'PASS' : 'WARN';
    }

    private function transferStatus(array $coverage): string
    {
        if (! isset($coverage['total'], $coverage['graph_route_connected'], $coverage['records_marked_verified'])) {
            return 'BLOCKED';
        }
        if ((int) $coverage['total'] <= 0) {
            return 'BLOCKED';
        }
        if ((int) $coverage['graph_route_connected'] < 0 || (int) $coverage['graph_route_connected'] > (int) $coverage['total']
            || (int) $coverage['records_marked_verified'] < 0 || (int) $coverage['records_marked_verified'] > (int) $coverage['total']) {
            return 'FAIL';
        }

        return (int) $coverage['graph_route_connected'] === (int) $coverage['total']
            && (int) $coverage['records_marked_verified'] === (int) $coverage['total'] ? 'PASS' : 'WARN';
    }

    private function coverageCounts(array $coverage): array
    {
        return array_intersect_key($coverage, array_flip([
            'total', 'with_usable_coordinates', 'within_snap_policy', 'connected_to_largest_component',
            'snapped_to_smaller_components', 'out_of_scope', 'blocked_by_mapped_barrier', 'unreachable_or_unsnappable',
        ]));
    }

    private function transferCounts(array $coverage): array
    {
        return array_intersect_key($coverage, array_flip(['total', 'records_marked_verified', 'graph_route_connected', 'graph_route_unavailable']));
    }
}
