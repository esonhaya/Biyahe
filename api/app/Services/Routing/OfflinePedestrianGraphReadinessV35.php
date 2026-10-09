<?php

namespace App\Services\Routing;

/** Static Batch035 readiness from saved evidence; never connects to PostgreSQL. */
final class OfflinePedestrianGraphReadinessV35
{
    public function build(
        array $doctorV34,
        array $resourceEvidence,
        array $cancellationEvidence,
        array $clusterEvidence,
        array $importEvidence,
        array $coverageEvidence,
    ): array {
        $checksV34 = $this->indexChecks($doctorV34['checks'] ?? []);
        $sourceHash = strtolower((string) ($doctorV34['source_sha256'] ?? ''));
        $graphHash = strtolower((string) ($doctorV34['graph_sha256'] ?? ''));
        $hashesValid = preg_match('/^[a-f0-9]{64}$/', $sourceHash) === 1
            && preg_match('/^[a-f0-9]{64}$/', $graphHash) === 1
            && ($doctorV34['format'] ?? null) === 'biyahe-doctor-v34-static-v1';

        $resourceSamples = is_array($resourceEvidence['samples'] ?? null) ? $resourceEvidence['samples'] : [];
        $resourceGuard = is_array($resourceEvidence['guard'] ?? null) ? $resourceEvidence['guard'] : [];
        $minMemory = (int) ($resourceGuard['minimum_memavailable_kib'] ?? 0);
        $minSwap = (int) ($resourceGuard['minimum_swapfree_kib'] ?? 0);
        $minDisk = (int) ($resourceGuard['minimum_diskfree_kib'] ?? 0);
        $resourceStable = ($resourceEvidence['format'] ?? null) === 'biyahe-batch035-resource-stability-v1'
            && count($resourceSamples) >= 3 && $minMemory > 0 && $minSwap > 0 && $minDisk > 0;
        foreach ($resourceSamples as $sample) {
            $resourceStable = $resourceStable && is_array($sample)
                && (int) ($sample['memavailable_kib'] ?? 0) >= $minMemory
                && (int) ($sample['swapfree_kib'] ?? 0) >= $minSwap
                && (int) ($sample['diskfree_kib'] ?? 0) >= $minDisk;
        }
        $resourceStatus = $resourceStable ? 'PASS' : 'BLOCKED';

        $cancelStatus = ($cancellationEvidence['format'] ?? null) === 'biyahe-batch035-cancellation-probe-v1'
            && ($cancellationEvidence['status'] ?? null) === 'PASS'
            && ($cancellationEvidence['worker_group_verified'] ?? false) === true
            && ($cancellationEvidence['worker_and_child_stopped'] ?? false) === true
            && ($cancellationEvidence['parent_shell_survived'] ?? false) === true
            && ($cancellationEvidence['unrelated_sibling_survived'] ?? false) === true
            && ($cancellationEvidence['full_worker_log_retained'] ?? false) === true
            && ($cancellationEvidence['termination_sequence'] ?? null) === ['SIGTERM', 'SIGKILL']
            && (int) ($cancellationEvidence['worker_exit'] ?? -1) === 137
            && ($cancellationEvidence['worker_exit_signal'] ?? null) === 'SIGKILL'
            ? 'PASS' : 'BLOCKED';

        $clusterDataDirectory = rtrim((string) ($clusterEvidence['data_directory'] ?? ''), '/');
        $clusterSocketDirectory = rtrim((string) ($clusterEvidence['socket_directory'] ?? ''), '/');
        $clusterIdentityValid = ($clusterEvidence['format'] ?? null) === 'biyahe-batch035-isolated-cluster-evidence-v1'
            && ($clusterEvidence['system_identifier'] ?? null) === '7694494303197720087'
            && (int) ($clusterEvidence['port'] ?? 0) === 56433
            && ($clusterEvidence['pg_version'] ?? null) === '18'
            && ($clusterEvidence['listen_addresses'] ?? null) === '127.0.0.1'
            && ($clusterEvidence['data_directory_matches_expected'] ?? false) === true
            && ($clusterEvidence['socket_directory_matches_expected'] ?? false) === true
            && str_ends_with($clusterDataDirectory, '/.cache/biyahe/batch033-isolated-20261009/data')
            && str_ends_with($clusterSocketDirectory, '/.cache/biyahe/batch033-isolated-20261009/socket')
            && ($clusterEvidence['owner_matches_current_user'] ?? false) === true
            && ($clusterEvidence['cluster_state'] ?? null) === 'shut down'
            && ($clusterEvidence['postmaster_pid_present'] ?? true) === false
            && ($clusterEvidence['original_cluster_touched'] ?? true) === false;
        $clusterStatus = $clusterIdentityValid ? 'PASS' : 'BLOCKED';

        $databaseQueried = ($importEvidence['format'] ?? null) === 'biyahe-batch035-import-state-v1'
            && ($importEvidence['database_queried'] ?? false) === true
            && ($importEvidence['isolated_data_directory_verified'] ?? false) === true
            && ($importEvidence['original_cluster_touched'] ?? true) === false;
        $checkpoint = is_array($importEvidence['checkpoint'] ?? null) ? $importEvidence['checkpoint'] : [];
        $checkpointValid = $databaseQueried && ($checkpoint['queried'] ?? false) === true
            && ($checkpoint['source_hash_matches'] ?? false) === true
            && ($checkpoint['graph_fingerprint_matches'] ?? false) === true
            && ($checkpoint['committed_prefix_matches'] ?? false) === true;
        $graphCheckEvidence = is_array($checksV34['graph integrity']['evidence'] ?? null)
            ? $checksV34['graph integrity']['evidence'] : [];
        $expected = is_array($graphCheckEvidence['evidence'] ?? null)
            ? $graphCheckEvidence['evidence'] : $graphCheckEvidence;
        $expectedNodes = (int) ($expected['nodes'] ?? -1);
        $expectedEdges = (int) ($expected['edges'] ?? -1);
        $importComplete = $databaseQueried && $hashesValid
            && ($importEvidence['state'] ?? null) === 'VALIDATED'
            && (int) ($importEvidence['nodes'] ?? -1) === $expectedNodes
            && (int) ($importEvidence['edges'] ?? -1) === $expectedEdges
            && ($importEvidence['source_parity'] ?? false) === true
            && ($importEvidence['geometry_parity'] ?? false) === true
            && ($importEvidence['directionality_parity'] ?? false) === true
            && ($importEvidence['is_active'] ?? true) === false;

        $coverageCheck = is_array($checksV34['source-bound stop and place coverage']['evidence'] ?? null)
            ? $checksV34['source-bound stop and place coverage']['evidence'] : [];
        $coverageMatches = ($coverageEvidence['format'] ?? null) === 'biyahe-cebu-offline-coverage-v1'
            && hash_equals($sourceHash, strtolower((string) ($coverageEvidence['source']['graph_source_sha256'] ?? '')))
            && ($coverageCheck['source_backed'] ?? false) === true
            && (float) ($coverageEvidence['snap_policy']['maximum_distance_meters'] ?? 0) === 25.0;
        $coverage = is_array($coverageEvidence['coverage'] ?? null) ? $coverageEvidence['coverage'] : [];
        $stops = is_array($coverage['stops'] ?? null) ? $coverage['stops'] : [];
        $places = is_array($coverage['places'] ?? null) ? $coverage['places'] : [];
        $transfers = is_array($coverage['transfers'] ?? null) ? $coverage['transfers'] : [];
        $transferStatus = $coverageMatches
            ? ((int) ($transfers['total'] ?? 0) > 0 && (int) ($transfers['records_marked_verified'] ?? -1) === (int) ($transfers['total'] ?? 0) ? 'PASS' : 'WARN')
            : 'BLOCKED';

        $makeCheck = static fn (string $name, string $status, array $evidence = []): array => [
            'name' => $name,
            'status' => $status,
            'evidence' => $evidence,
        ];
        $checks = [
            $makeCheck('source integrity', $hashesValid ? ($checksV34['source integrity']['status'] ?? 'BLOCKED') : 'FAIL', ['sha256' => $sourceHash]),
            $makeCheck('graph integrity', $hashesValid ? ($checksV34['graph integrity']['status'] ?? 'BLOCKED') : 'FAIL', ['sha256' => $graphHash, 'nodes' => $expectedNodes, 'edges' => $expectedEdges]),
            $makeCheck('restriction readiness', $checksV34['restriction readiness']['status'] ?? 'BLOCKED', $checksV34['restriction readiness']['evidence'] ?? []),
            $makeCheck('offline routing', $checksV34['offline routing']['status'] ?? 'BLOCKED', $checksV34['offline routing']['evidence'] ?? []),
            $makeCheck('resource guard cancellation', $cancelStatus, [
                'worker_group_verified' => $cancellationEvidence['worker_group_verified'] ?? false,
                'worker_and_child_stopped' => $cancellationEvidence['worker_and_child_stopped'] ?? false,
                'parent_shell_survived' => $cancellationEvidence['parent_shell_survived'] ?? false,
                'unrelated_sibling_survived' => $cancellationEvidence['unrelated_sibling_survived'] ?? false,
                'full_worker_log_retained' => $cancellationEvidence['full_worker_log_retained'] ?? false,
                'worker_exit' => $cancellationEvidence['worker_exit'] ?? null,
                'worker_exit_signal' => $cancellationEvidence['worker_exit_signal'] ?? null,
            ]),
            $makeCheck('resource stability', $resourceStatus, [
                'samples' => count($resourceSamples),
                'minimum_memavailable_kib' => $minMemory,
                'minimum_swapfree_kib' => $minSwap,
                'minimum_diskfree_kib' => $minDisk,
                'observed_min_swapfree_kib' => $resourceEvidence['observed_min_swapfree_kib'] ?? null,
                'observed_min_memavailable_kib' => $resourceEvidence['observed_min_memavailable_kib'] ?? null,
                'log' => $resourceEvidence['log'] ?? null,
            ]),
            $makeCheck('isolated cluster identity', $clusterStatus, $clusterEvidence),
            $makeCheck('checkpoint integrity', $checkpointValid ? 'PASS' : 'BLOCKED', $checkpoint !== [] ? $checkpoint : ['state' => 'NOT_QUERIED']),
            $makeCheck('real import completeness', $importComplete ? 'PASS' : 'BLOCKED', [
                'database_queried' => $databaseQueried,
                'state' => $importEvidence['state'] ?? 'NOT_QUERIED',
                'nodes' => $importEvidence['nodes'] ?? null,
                'expected_nodes' => $expectedNodes,
                'edges' => $importEvidence['edges'] ?? null,
                'expected_edges' => $expectedEdges,
            ]),
            $makeCheck('real rollback', ($importEvidence['rollback']['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED', $importEvidence['rollback'] ?? ['status' => 'NOT_RUN']),
            $makeCheck('real idempotency', ($importEvidence['idempotency']['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED', $importEvidence['idempotency'] ?? ['status' => 'NOT_RUN']),
            $makeCheck('conflict rejection', ($importEvidence['conflict_rejection']['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED', $importEvidence['conflict_rejection'] ?? ['status' => 'NOT_RUN']),
            $makeCheck('offline staged route parity', ($importEvidence['route_parity']['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED', $importEvidence['route_parity'] ?? ['status' => 'NOT_RUN']),
            $makeCheck('stop and place coverage', $coverageMatches ? 'WARN' : 'BLOCKED', [
                'source_backed' => $coverageMatches,
                'snap_radius_meters' => $coverageMatches ? ($coverageEvidence['snap_policy']['maximum_distance_meters'] ?? null) : null,
                'stops_total' => $stops['total'] ?? null,
                'stops_snappable' => $stops['within_snap_policy'] ?? null,
                'stops_unreachable_or_unsnappable' => $stops['unreachable_or_unsnappable'] ?? null,
                'places_total' => $places['total'] ?? null,
                'places_snappable' => $places['within_snap_policy'] ?? null,
                'places_unreachable_or_unsnappable' => $places['unreachable_or_unsnappable'] ?? null,
            ]),
            $makeCheck('transfer verification', $transferStatus, $transfers),
            $makeCheck('field safety evidence', ($checksV34['field safety evidence']['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED', $checksV34['field safety evidence']['evidence'] ?? ['status' => 'MISSING']),
            $makeCheck('production activation', ($clusterEvidence['production_activation'] ?? 'NO') === 'NO' ? 'PASS' : 'BLOCKED', ['activation' => 'NO', 'activated' => false]),
        ];
        $byName = array_column($checks, null, 'name');
        $required = [
            'source integrity', 'graph integrity', 'restriction readiness', 'offline routing',
            'resource guard cancellation', 'resource stability', 'isolated cluster identity',
            'checkpoint integrity', 'real import completeness', 'real rollback', 'real idempotency',
            'conflict rejection', 'offline staged route parity', 'stop and place coverage',
            'transfer verification', 'field safety evidence',
        ];
        $betaReady = true;
        foreach ($required as $name) {
            $betaReady = $betaReady && ($byName[$name]['status'] ?? null) === 'PASS';
        }
        $checks[] = $makeCheck('private beta readiness', $betaReady ? 'PASS' : 'BLOCKED', [
            'all_required_gates_pass' => $betaReady,
            'activation' => 'NO',
        ]);
        $statuses = array_column($checks, 'status');

        return [
            'format' => 'biyahe-doctor-v35-static-v1',
            'status' => in_array('FAIL', $statuses, true) ? 'FAIL' : (in_array('BLOCKED', $statuses, true) ? 'BLOCKED' : (in_array('WARN', $statuses, true) ? 'WARN' : 'PASS')),
            'postgresql_contacted' => false,
            'activation' => 'NO',
            'source_sha256' => $sourceHash,
            'graph_sha256' => $graphHash,
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
}
