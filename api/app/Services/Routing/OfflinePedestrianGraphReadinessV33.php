<?php

namespace App\Services\Routing;

/** Static Batch033 readiness from hash-bound Batch032 and Batch033 evidence. */
final class OfflinePedestrianGraphReadinessV33
{
    public function build(array $baseline, array $staging, ?array $parity): array
    {
        $graphHash = strtolower((string) ($baseline['graph_sha256'] ?? ''));
        $sourceHash = strtolower((string) ($baseline['source_sha256'] ?? ''));
        $identityMatches = preg_match('/^[a-f0-9]{64}$/', $graphHash) === 1
            && preg_match('/^[a-f0-9]{64}$/', $sourceHash) === 1
            && hash_equals($graphHash, strtolower((string) ($staging['identity']['graph_sha256'] ?? '')))
            && hash_equals($sourceHash, strtolower((string) ($staging['identity']['source_sha256'] ?? '')))
            && ($staging['identity']['existing_cluster_touched'] ?? true) === false;
        $sourceCheck = $this->baselineCheck($baseline, 'source verification');
        $graphCheck = $this->baselineCheck($baseline, 'graph structural integrity');
        $restrictionCheck = $this->baselineCheck($baseline, 'snapshot routing capability');
        $offlineRouteCheck = $this->baselineCheck($baseline, 'real journey corpus');
        $sourceStatus = $identityMatches && ($sourceCheck['status'] ?? null) === 'PASS' ? 'PASS' : 'FAIL';
        $graphStatus = $identityMatches && ($graphCheck['status'] ?? null) === 'PASS' ? 'PASS' : 'FAIL';
        $restrictionStatus = ($restrictionCheck['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';
        $offlineRouteStatus = ($offlineRouteCheck['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';

        $resources = is_array($staging['resource_preflight'] ?? null) ? $staging['resource_preflight'] : [];
        $resourceStatus = in_array($resources['status'] ?? null, ['PASS', 'WARN', 'BLOCKED', 'FAIL'], true)
            ? $resources['status'] : 'BLOCKED';
        $import = is_array($staging['import'] ?? null) ? $staging['import'] : [];
        $expectedNodes = (int) ($graphCheck['evidence']['nodes'] ?? -1);
        $expectedEdges = (int) ($graphCheck['evidence']['edges'] ?? -1);
        $importComplete = $identityMatches && ($import['status'] ?? null) === 'PASS'
            && (int) ($import['nodes'] ?? 0) === $expectedNodes
            && (int) ($import['edges'] ?? 0) === $expectedEdges
            && ($import['state'] ?? null) === 'VALIDATED'
            && ($import['is_active'] ?? true) === false
            && ($staging['activation'] ?? null) === 'NO';
        $rollbackStatus = ($import['rollback']['status'] ?? null) === 'PASS' && $identityMatches ? 'PASS' : 'BLOCKED';
        $idempotencyStatus = ($import['idempotency']['status'] ?? null) === 'PASS' && $identityMatches ? 'PASS' : 'BLOCKED';
        $interruptionStatus = ($import['interruption_recovery']['status'] ?? null) === 'PASS' && $identityMatches ? 'PASS' : 'BLOCKED';
        $partialStatus = ($import['partial_visibility']['status'] ?? null) === 'PASS' && $identityMatches ? 'PASS' : 'BLOCKED';
        $conflictStatus = ($import['conflict_rejection']['status'] ?? null) === 'PASS' && $identityMatches ? 'PASS' : 'BLOCKED';
        $parityIdentity = $parity !== null
            && hash_equals($graphHash, strtolower((string) ($parity['identity']['graph_sha256'] ?? '')))
            && hash_equals($sourceHash, strtolower((string) ($parity['identity']['source_sha256'] ?? '')))
            && ($parity['identity']['is_active'] ?? true) === false
            && ($parity['identity']['activation'] ?? null) === 'NO';
        $parityStatus = $parityIdentity && ($parity['status'] ?? null) === 'PASS'
            && (int) ($parity['matches'] ?? 0) === (int) ($parity['case_count'] ?? -1)
            && (int) ($parity['mismatches'] ?? -1) === 0 ? 'PASS' : 'BLOCKED';
        $coverage = $this->baselineCheck($baseline, 'stop coverage');
        $transfer = $this->baselineCheck($baseline, 'transfer coverage and trust');
        $field = $this->baselineCheck($baseline, 'field verification');
        $fieldStatus = ($field['status'] ?? null) === 'PASS' && ($field['evidence']['complete'] ?? false) === true ? 'PASS' : 'BLOCKED';

        $checks = [
            $this->check('source integrity', $sourceStatus, ['sha256' => $sourceHash, 'baseline_status' => $sourceCheck['status'] ?? 'MISSING', 'identity_matches' => $identityMatches]),
            $this->check('graph integrity', $graphStatus, ['sha256' => $graphHash, 'nodes' => $expectedNodes, 'edges' => $expectedEdges, 'baseline_status' => $graphCheck['status'] ?? 'MISSING', 'identity_matches' => $identityMatches]),
            $this->check('restriction readiness', $restrictionStatus, ['status' => $restrictionCheck['status'] ?? 'MISSING', 'evidence' => $restrictionCheck['evidence'] ?? []]),
            $this->check('offline route readiness', $offlineRouteStatus, ['status' => $offlineRouteCheck['status'] ?? 'MISSING', 'evidence' => $offlineRouteCheck['evidence'] ?? []]),
            $this->check('staging resource readiness', $resourceStatus, $resources),
            $this->check('real import completeness', $importComplete ? 'PASS' : 'BLOCKED', ['status' => $import['status'] ?? 'NOT_RUN', 'nodes' => (int) ($import['nodes'] ?? 0), 'expected_nodes' => $expectedNodes, 'edges' => (int) ($import['edges'] ?? 0), 'expected_edges' => $expectedEdges, 'state' => $import['state'] ?? 'UNKNOWN', 'is_active' => $import['is_active'] ?? null]),
            $this->check('real rollback', $rollbackStatus, $import['rollback'] ?? ['status' => 'NOT_RUN']),
            $this->check('real idempotency', $idempotencyStatus, $import['idempotency'] ?? ['status' => 'NOT_RUN']),
            $this->check('interruption recovery', $interruptionStatus, $import['interruption_recovery'] ?? ['status' => 'NOT_RUN']),
            $this->check('partial visibility guard', $partialStatus, $import['partial_visibility'] ?? ['status' => 'NOT_RUN']),
            $this->check('conflicting artifact rejection', $conflictStatus, $import['conflict_rejection'] ?? ['status' => 'NOT_RUN']),
            $this->check('offline/staged route parity', $parityStatus, $parity === null ? ['status' => 'NOT_RUN'] : ['identity_matches' => $parityIdentity, 'status' => $parity['status'] ?? 'UNKNOWN', 'cases' => $parity['case_count'] ?? 0, 'matches' => $parity['matches'] ?? 0, 'mismatches' => $parity['mismatches'] ?? null, 'reason_code_exact_matches' => $parity['reason_code_exact_matches'] ?? null, 'reason_family_matches' => $parity['reason_family_matches'] ?? null]),
            $this->check('stop coverage limitations', ($coverage['status'] ?? null) === 'PASS' ? 'PASS' : 'WARN', $coverage['evidence'] ?? ['status' => 'MISSING']),
            $this->check('transfer verification', ($transfer['evidence']['records_marked_verified'] ?? null) === ($transfer['evidence']['total'] ?? -1) ? 'PASS' : 'WARN', $transfer['evidence'] ?? ['status' => 'MISSING']),
            $this->check('field safety evidence', $fieldStatus, $field['evidence'] ?? ['status' => 'MISSING']),
            $this->check('production activation', 'BLOCKED', ['activation' => 'NO', 'reason' => 'Batch033 never activates production routing.']),
        ];
        $privateBetaReady = $importComplete && $rollbackStatus === 'PASS' && $idempotencyStatus === 'PASS'
            && $interruptionStatus === 'PASS' && $partialStatus === 'PASS' && $conflictStatus === 'PASS'
            && $parityStatus === 'PASS' && $fieldStatus === 'PASS';
        $checks[] = $this->check('private beta readiness', $privateBetaReady ? 'PASS' : 'BLOCKED', [
            'import_complete' => $importComplete, 'rollback' => $rollbackStatus, 'idempotency' => $idempotencyStatus,
            'interruption_recovery' => $interruptionStatus, 'partial_visibility' => $partialStatus,
            'conflict_rejection' => $conflictStatus, 'route_parity' => $parityStatus, 'field_safety_evidence' => $fieldStatus,
        ]);
        $statuses = array_column($checks, 'status');

        return [
            'format' => 'biyahe-doctor-v33-static-v1',
            'status' => in_array('FAIL', $statuses, true) ? 'FAIL' : (in_array('BLOCKED', $statuses, true) ? 'BLOCKED' : (in_array('WARN', $statuses, true) ? 'WARN' : 'PASS')),
            'postgresql_contacted' => false,
            'graph_sha256' => $graphHash,
            'source_sha256' => $sourceHash,
            'checks' => $checks,
        ];
    }

    private function baselineCheck(array $baseline, string $name): array
    {
        foreach ($baseline['checks'] ?? [] as $check) {
            if (($check['name'] ?? null) === $name) {
                return $check;
            }
        }

        return [];
    }

    private function check(string $name, string $status, array $evidence): array
    {
        return ['name' => $name, 'status' => $status, 'evidence' => $evidence];
    }
}
