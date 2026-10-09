<?php

namespace App\Services\Routing;

/** Static Batch034 readiness; consumes evidence files and never contacts PostgreSQL. */
final class OfflinePedestrianGraphReadinessV34
{
    public function build(array $doctorV33, array $stagingEvidence, array $resources, ?array $coverage = null, ?array $parity = null): array
    {
        $checksV33 = $this->indexChecks($doctorV33['checks'] ?? []);
        $identity = is_array($stagingEvidence['identity'] ?? null) ? $stagingEvidence['identity'] : [];
        $sourceHash = strtolower((string) ($doctorV33['source_sha256'] ?? ''));
        $graphHash = strtolower((string) ($doctorV33['graph_sha256'] ?? ''));
        $hashesValid = preg_match('/^[a-f0-9]{64}$/', $sourceHash) === 1
            && preg_match('/^[a-f0-9]{64}$/', $graphHash) === 1
            && ($doctorV33['format'] ?? null) === 'biyahe-doctor-v33-static-v1'
            && ($stagingEvidence['format'] ?? null) === 'biyahe-batch033-staging-evidence-v1'
            && hash_equals($sourceHash, strtolower((string) ($identity['source_sha256'] ?? '')))
            && hash_equals($graphHash, strtolower((string) ($identity['graph_sha256'] ?? '')));

        $resourceStatus = 'BLOCKED';
        if (($resources['format'] ?? null) === 'biyahe-termux-resource-diagnostic-v1') {
            $last = is_array($resources['latest_sample'] ?? null) ? $resources['latest_sample'] : [];
            $samples = is_array($resources['samples'] ?? null) ? $resources['samples'] : [];
            $minimumMemory = (int) ($resources['guard']['minimum_memavailable_kib'] ?? 0);
            $minimumSwap = (int) ($resources['guard']['minimum_swapfree_kib'] ?? 0);
            $samplesStayAboveFloors = count($samples) >= 3;
            foreach ($samples as $sample) {
                $samplesStayAboveFloors = $samplesStayAboveFloors
                    && is_array($sample)
                    && (int) ($sample['memavailable_kib'] ?? 0) >= $minimumMemory
                    && (int) ($sample['swapfree_kib'] ?? 0) >= $minimumSwap;
            }
            $resourceStatus = $minimumMemory > 0 && $minimumSwap > 0 && $samplesStayAboveFloors
                && (int) ($last['memavailable_kib'] ?? 0) >= $minimumMemory
                && (int) ($last['swapfree_kib'] ?? 0) >= $minimumSwap
                ? 'PASS' : 'BLOCKED';
        }

        $batch034 = is_array($stagingEvidence['batch034'] ?? null) ? $stagingEvidence['batch034'] : [];
        $databaseInspection = is_array($batch034['database_inspection'] ?? null) ? $batch034['database_inspection'] : [];
        $persistedStateWasQueried = ($databaseInspection['queried'] ?? false) === true
            && ($databaseInspection['data_directory_matches_isolated'] ?? false) === true
            && ($databaseInspection['original_cluster_touched'] ?? true) === false;
        $checkpoint = is_array($databaseInspection['checkpoint'] ?? null) ? $databaseInspection['checkpoint'] : [];
        $checkpointStatus = $persistedStateWasQueried && ($checkpoint['queried'] ?? false) === true
            && ($checkpoint['prefix_verified'] ?? false) === true
            && ($checkpoint['identity_matches'] ?? false) === true ? 'PASS' : 'BLOCKED';
        $importCheck = $checksV33['real import completeness'] ?? [];
        $priorImportEvidence = is_array($importCheck['evidence'] ?? null) ? $importCheck['evidence'] : [];
        $graphEvidence = is_array($checksV33['graph integrity']['evidence'] ?? null) ? $checksV33['graph integrity']['evidence'] : [];
        $expectedNodes = (int) ($graphEvidence['nodes'] ?? -1);
        $expectedEdges = (int) ($graphEvidence['edges'] ?? -1);
        $importComplete = $persistedStateWasQueried
            && $hashesValid
            && ($databaseInspection['state'] ?? null) === 'VALIDATED'
            && hash_equals($graphHash, strtolower((string) ($databaseInspection['graph_sha256'] ?? '')))
            && hash_equals($sourceHash, strtolower((string) ($databaseInspection['source_sha256'] ?? '')))
            && (int) ($databaseInspection['nodes'] ?? -1) === $expectedNodes
            && (int) ($databaseInspection['edges'] ?? -1) === $expectedEdges
            && ($databaseInspection['is_active'] ?? true) === false
            && ($databaseInspection['activation'] ?? 'UNKNOWN') === 'NO'
            && ($databaseInspection['source_parity'] ?? false) === true
            && ($databaseInspection['geometry_parity'] ?? false) === true;
        $importStatus = $importComplete ? 'PASS' : 'BLOCKED';
        $importEvidence = [
            'persisted_state_queried_in_batch034' => $persistedStateWasQueried,
            'state' => $databaseInspection['state'] ?? 'NOT_QUERIED',
            'nodes' => $databaseInspection['nodes'] ?? null,
            'expected_nodes' => $expectedNodes,
            'edges' => $databaseInspection['edges'] ?? null,
            'expected_edges' => $expectedEdges,
            'prior_batch033_observation' => $priorImportEvidence,
        ];
        $currentOperation = static function (string $name) use ($batch034): array {
            $result = $batch034[$name] ?? null;

            return is_array($result) ? $result : ['status' => 'NOT_RUN'];
        };
        $rollbackEvidence = $currentOperation('real_rollback');
        $interruptionEvidence = $currentOperation('interruption_recovery');
        $partialVisibilityEvidence = $currentOperation('partial_visibility');
        $idempotencyEvidence = $currentOperation('idempotency');
        $conflictEvidence = $currentOperation('conflict_rejection');
        $rollbackStatus = ($rollbackEvidence['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';
        $interruptionStatus = ($interruptionEvidence['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';
        $partialVisibilityStatus = ($partialVisibilityEvidence['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';
        $idempotencyStatus = ($idempotencyEvidence['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';
        $conflictStatus = ($conflictEvidence['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';
        $parityCheck = ['status' => 'BLOCKED', 'evidence' => ['status' => 'NOT_RUN']];
        if ($parity !== null && $importComplete && ($parity['status'] ?? null) === 'PASS'
            && (int) ($parity['matches'] ?? -1) === (int) ($parity['case_count'] ?? 0)
            && (int) ($parity['mismatches'] ?? -1) === 0
            && hash_equals($graphHash, strtolower((string) ($parity['identity']['graph_sha256'] ?? '')))
            && hash_equals($sourceHash, strtolower((string) ($parity['identity']['source_sha256'] ?? '')))) {
            $parityCheck = ['status' => 'PASS', 'evidence' => ['cases' => $parity['case_count'], 'matches' => $parity['matches'], 'mismatches' => $parity['mismatches']]];
        }
        $field = $checksV33['field safety evidence'] ?? ['status' => 'BLOCKED', 'evidence' => ['status' => 'MISSING']];
        $transfer = $checksV33['transfer verification'] ?? ['status' => 'WARN', 'evidence' => ['status' => 'MISSING']];
        $stopCoverage = $checksV33['stop coverage limitations'] ?? ['status' => 'WARN', 'evidence' => ['status' => 'MISSING']];
        $fieldEvidence = is_array($field['evidence'] ?? null) ? $field['evidence'] : [];
        $fieldStatus = ($field['status'] ?? null) === 'PASS' && ($fieldEvidence['complete'] ?? false) === true ? 'PASS' : 'BLOCKED';
        $coverageMatches = $coverage !== null
            && ($coverage['format'] ?? null) === 'biyahe-cebu-offline-coverage-v1'
            && hash_equals($sourceHash, strtolower((string) ($coverage['source']['graph_source_sha256'] ?? '')))
            && hash_equals(strtolower((string) ($identity['graph_fingerprint'] ?? '')), strtolower((string) ($coverage['source']['graph_fingerprint'] ?? '')))
            && (float) ($coverage['snap_policy']['maximum_distance_meters'] ?? 0) === 25.0;
        $stopsCoverage = is_array($coverage['coverage']['stops'] ?? null) ? $coverage['coverage']['stops'] : [];
        $placesCoverage = is_array($coverage['coverage']['places'] ?? null) ? $coverage['coverage']['places'] : [];
        $transferCoverage = is_array($coverage['coverage']['transfers'] ?? null) ? $coverage['coverage']['transfers'] : [];
        $coverageSummary = [
            'source_backed' => $coverageMatches,
            'snap_radius_meters' => $coverageMatches ? 25 : null,
            'stops_total' => $stopsCoverage['total'] ?? null,
            'stops_snappable' => $stopsCoverage['within_snap_policy'] ?? null,
            'stops_largest_component' => $stopsCoverage['connected_to_largest_component'] ?? null,
            'stops_unreachable_or_unsnappable' => $stopsCoverage['unreachable_or_unsnappable'] ?? null,
            'stops_unsnappable' => $stopsCoverage['snap_reason_counts']['snap_unavailable'] ?? null,
            'places_total' => $placesCoverage['total'] ?? null,
            'places_snappable' => $placesCoverage['within_snap_policy'] ?? null,
            'places_largest_component' => $placesCoverage['connected_to_largest_component'] ?? null,
            'places_unreachable_or_unsnappable' => $placesCoverage['unreachable_or_unsnappable'] ?? null,
            'places_unsnappable' => $placesCoverage['snap_reason_counts']['snap_unavailable'] ?? null,
        ];
        $measuredCoverageStatus = $coverageMatches ? 'WARN' : 'BLOCKED';
        $transferEvidence = $coverageMatches ? $transferCoverage : (is_array($transfer['evidence'] ?? null) ? $transfer['evidence'] : []);
        $transferTotal = (int) ($transferEvidence['total'] ?? -1);
        $transferVerified = (int) ($transferEvidence['records_marked_verified'] ?? -1);
        $transferStatus = ! $coverageMatches ? 'BLOCKED' : ($transferTotal > 0 && $transferVerified === $transferTotal ? 'PASS' : 'WARN');
        $coverageEvidence = is_array($stopCoverage['evidence'] ?? null) ? $stopCoverage['evidence'] : [];
        $coverageTotal = (int) ($coverageEvidence['total'] ?? 0);
        $coverageSnappable = (int) ($coverageEvidence['within_snap_policy'] ?? 0);
        $coverageConnected = (int) ($coverageEvidence['connected_to_largest_component'] ?? 0);
        $coverageStatus = ! $coverageMatches ? 'BLOCKED' : ($coverageTotal > 0 && $coverageSnappable === $coverageTotal && $coverageConnected === $coverageTotal ? 'PASS' : 'WARN');
        $activation = ($stagingEvidence['production_activation'] ?? 'UNKNOWN') === 'NO'
            && ($identity['existing_cluster_touched'] ?? true) === false ? 'PASS' : 'BLOCKED';

        $checks = [
            $this->check('source integrity', $hashesValid ? ($checksV33['source integrity']['status'] ?? 'BLOCKED') : 'FAIL', ['sha256' => $sourceHash, 'identity_matches' => $hashesValid]),
            $this->check('graph integrity', $hashesValid ? ($checksV33['graph integrity']['status'] ?? 'BLOCKED') : 'FAIL', ['sha256' => $graphHash, 'evidence' => $checksV33['graph integrity']['evidence'] ?? []]),
            $this->check('restriction readiness', $checksV33['restriction readiness']['status'] ?? 'BLOCKED', $checksV33['restriction readiness']['evidence'] ?? []),
            $this->check('offline routing', $checksV33['offline route readiness']['status'] ?? 'BLOCKED', $checksV33['offline route readiness']['evidence'] ?? []),
            $this->check('staging resource readiness', $resourceStatus, $resources['latest_sample'] ?? ['status' => 'MISSING']),
            $this->check('import state', $importComplete ? 'PASS' : 'BLOCKED', $importEvidence),
            $this->check('checkpoint validity', $checkpointStatus, $checkpoint !== [] ? $checkpoint : ['status' => 'NOT_QUERIED', 'data_directory_must_remain_stopped_until_guard_passes' => true]),
            $this->check('real artifact completeness', $importStatus, $importEvidence),
            $this->check('real rollback', $rollbackStatus, ['current_batch034' => $rollbackEvidence, 'prior_batch033' => $checksV33['real rollback']['evidence'] ?? ['status' => 'NOT_RUN']]),
            $this->check('interruption recovery', $interruptionStatus, ['current_batch034' => $interruptionEvidence, 'prior_batch033' => $checksV33['interruption recovery']['evidence'] ?? ['status' => 'NOT_RUN']]),
            $this->check('partial import visibility', $partialVisibilityStatus, ['current_batch034' => $partialVisibilityEvidence, 'prior_batch033' => $checksV33['partial visibility guard']['evidence'] ?? ['status' => 'NOT_RUN']]),
            $this->check('real idempotency', $idempotencyStatus, $idempotencyEvidence),
            $this->check('conflict rejection', $conflictStatus, $conflictEvidence),
            $this->check('route parity', $parityCheck['status'] ?? 'BLOCKED', $parityCheck['evidence'] ?? ['status' => 'NOT_RUN']),
            $this->check('stop coverage limitations', $coverageStatus, $coverageEvidence),
            $this->check('source-bound stop and place coverage', $measuredCoverageStatus, $coverageSummary),
            $this->check('transfer verification', $transferStatus, $transferEvidence),
            $this->check('field safety evidence', $fieldStatus, $fieldEvidence),
            $this->check('production activation state', $activation, ['activation' => 'NO', 'activated' => false, 'reason' => 'Batch034 does not activate pedestrian routing.']),
        ];
        $betaGates = ['source integrity', 'graph integrity', 'restriction readiness', 'offline routing', 'staging resource readiness', 'import state', 'checkpoint validity', 'real artifact completeness', 'real rollback', 'interruption recovery', 'partial import visibility', 'real idempotency', 'conflict rejection', 'route parity', 'stop coverage limitations', 'source-bound stop and place coverage', 'transfer verification', 'field safety evidence'];
        $byName = array_column($checks, null, 'name');
        $betaReady = $hashesValid && ($identity['existing_cluster_touched'] ?? true) === false;
        foreach ($betaGates as $gate) {
            $betaReady = $betaReady && ($byName[$gate]['status'] ?? null) === 'PASS';
        }
        $checks[] = $this->check('private beta readiness', $betaReady ? 'PASS' : 'BLOCKED', [
            'all_required_gates_pass' => $betaReady,
            'unsnappable_stops' => $coverageMatches ? ($coverageSummary['stops_unsnappable'] ?? null) : null,
            'unsnappable_places' => $coverageMatches ? ($coverageSummary['places_unsnappable'] ?? null) : null,
            'transfer_locations_verified' => max(0, $transferVerified),
            'field_safety_evidence_complete' => ($fieldEvidence['complete'] ?? false) === true,
        ]);
        $statuses = array_column($checks, 'status');

        return [
            'format' => 'biyahe-doctor-v34-static-v1',
            'status' => in_array('FAIL', $statuses, true) ? 'FAIL' : (in_array('BLOCKED', $statuses, true) ? 'BLOCKED' : (in_array('WARN', $statuses, true) ? 'WARN' : 'PASS')),
            'postgresql_contacted' => false,
            'activation' => 'NO',
            'graph_sha256' => $graphHash,
            'source_sha256' => $sourceHash,
            'checks' => $checks,
        ];
    }

    private function indexChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            return [];
        }
        $indexed = [];
        foreach ($checks as $check) {
            if (is_array($check) && is_string($check['name'] ?? null)) {
                $indexed[$check['name']] = $check;
            }
        }

        return $indexed;
    }

    private function check(string $name, string $status, array $evidence): array
    {
        return ['name' => $name, 'status' => $status, 'evidence' => $evidence];
    }
}
