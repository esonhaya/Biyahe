<?php

namespace App\Services\Routing;

/** Batch036 release levels built only from saved evidence; never contacts PostgreSQL. */
final class OfflinePedestrianGraphReadinessV36
{
    public function build(
        array $coverage,
        array $doctorV35,
        array $packageManifest,
        array $packageVerification,
        array $validation,
        string $manifestSha256,
        string $coverageSha256,
    ): array {
        $sourceHash = strtolower((string) ($coverage['source']['graph_source_sha256'] ?? ''));
        $graphHash = strtolower((string) ($coverage['graph_validation']['artifact_sha256'] ?? ''));
        $graphFingerprint = strtolower((string) ($coverage['source']['graph_fingerprint'] ?? ''));
        $backupHash = strtolower((string) ($coverage['source']['transport_backup_sha256'] ?? ''));
        $graph = is_array($coverage['graph_validation'] ?? null) ? $coverage['graph_validation'] : [];
        $counts = is_array($graph['restriction_counts'] ?? null) ? $graph['restriction_counts'] : [];
        $manifestArtifacts = is_array($packageManifest['artifacts'] ?? null) ? $packageManifest['artifacts'] : [];
        $expected = [
            'source' => '787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d',
            'graph' => '29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8',
            'backup' => 'e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92',
        ];
        $expectedFingerprint = 'a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd';
        $artifactIntegrity = ($coverage['format'] ?? null) === 'biyahe-cebu-offline-coverage-v1'
            && hash_equals($expected['source'], $sourceHash)
            && hash_equals($expected['graph'], $graphHash)
            && hash_equals($expectedFingerprint, $graphFingerprint)
            && hash_equals($expected['backup'], $backupHash)
            && ($graph['artifact_ready'] ?? false) === true
            && ($graph['routing_ready'] ?? false) === true
            && ($graph['restriction_inventory_complete'] ?? false) === true
            && (int) ($graph['nodes'] ?? 0) === 76685
            && (int) ($graph['edges'] ?? 0) === 78225
            && (int) ($counts['total'] ?? 0) === 619
            && (int) ($counts['vehicle_only'] ?? -1) === 619
            && (int) ($counts['pedestrian_applicable'] ?? -1) === 0
            && (int) ($counts['ambiguous'] ?? -1) === 0
            && (int) ($counts['unsupported_applicable'] ?? -1) === 0;

        $journeyCases = is_array($coverage['journey_corpus']['cases'] ?? null) ? $coverage['journey_corpus']['cases'] : [];
        $routeCounts = ['ROUTE_FOUND' => 0, 'ROUTE_UNAVAILABLE' => 0];
        $evaluated = 0;
        foreach ($journeyCases as $case) {
            if (! is_array($case) || ($case['status'] ?? null) !== 'EVALUATED') {
                continue;
            }
            $evaluated++;
            $routeStatus = (string) ($case['route']['status'] ?? 'UNKNOWN');
            if (array_key_exists($routeStatus, $routeCounts)) {
                $routeCounts[$routeStatus]++;
            }
        }
        $routeEngineReady = $artifactIntegrity
            && ($coverage['complete'] ?? false) === true
            && $evaluated === 10
            && $routeCounts['ROUTE_FOUND'] === 9
            && $routeCounts['ROUTE_UNAVAILABLE'] === 1;

        $stopCoverage = is_array($coverage['coverage']['stops'] ?? null) ? $coverage['coverage']['stops'] : [];
        $placeCoverage = is_array($coverage['coverage']['places'] ?? null) ? $coverage['coverage']['places'] : [];
        $transfers = is_array($coverage['coverage']['transfers'] ?? null) ? $coverage['coverage']['transfers'] : [];
        $coverageValid = (int) ($stopCoverage['total'] ?? 0) === 503
            && (int) ($stopCoverage['within_snap_policy'] ?? -1) === 436
            && (int) ($placeCoverage['total'] ?? 0) === 37
            && (int) ($placeCoverage['within_snap_policy'] ?? -1) === 30
            && (int) ($transfers['total'] ?? 0) === 2
            && (int) ($transfers['records_marked_verified'] ?? -1) === 0
            && (int) ($coverage['snap_policy']['maximum_distance_meters'] ?? 0) === 25;
        $coverageStatus = $coverageValid ? 'WARN' : 'BLOCKED';

        $files = is_array($packageManifest['files'] ?? null) ? $packageManifest['files'] : [];
        $packageRestrictions = is_array($packageManifest['restriction_inventory'] ?? null) ? $packageManifest['restriction_inventory'] : [];
        $verifiedRestrictions = is_array($packageVerification['restriction_inventory'] ?? null) ? $packageVerification['restriction_inventory'] : [];
        $manifestSha256 = strtolower($manifestSha256);
        $restrictionAuditPath = 'evidence/batch030-cebu-restriction-audit.json';
        $restrictionEvidenceComplete = ($packageRestrictions['audit_filename'] ?? null) === $restrictionAuditPath
            && preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($packageRestrictions['audit_sha256'] ?? ''))) === 1
            && hash_equals(strtolower((string) ($packageRestrictions['audit_sha256'] ?? '')), strtolower((string) ($files[$restrictionAuditPath] ?? '')))
            && ($packageRestrictions['source_scan_complete'] ?? false) === true
            && (int) ($packageRestrictions['producer_exit_code'] ?? -1) === 0
            && (int) ($packageRestrictions['source_relations'] ?? -1) === 648
            && (int) ($packageRestrictions['stored_relations'] ?? -1) === 619
            && (int) ($packageRestrictions['omitted_relations'] ?? -1) === 29
            && (int) ($packageRestrictions['vehicle_only'] ?? -1) === 648
            && (int) ($packageRestrictions['pedestrian_applicable'] ?? -1) === 0
            && (int) ($packageRestrictions['pedestrian_exempt'] ?? -1) === 0
            && (int) ($packageRestrictions['ambiguous'] ?? -1) === 0
            && (int) ($packageRestrictions['unsupported_applicable'] ?? -1) === 0
            && (int) ($packageRestrictions['conditional'] ?? -1) === 33
            && (int) ($packageRestrictions['via_node'] ?? -1) === 449
            && (int) ($packageRestrictions['via_way'] ?? -1) === 166
            && (int) ($packageRestrictions['malformed_or_complex'] ?? -1) === 4
            && (int) ($packageRestrictions['unresolved_member_references'] ?? -1) === 75
            && $verifiedRestrictions === $packageRestrictions;
        $packageReady = ($packageManifest['format'] ?? null) === 'biyahe-portable-pedestrian-staging-v1'
            && (int) ($packageManifest['package_version'] ?? 0) === 1
            && preg_match('/^[a-f0-9]{40}$/', (string) ($packageManifest['repository']['core_commit'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/', $manifestSha256) === 1
            && preg_match('/^[a-f0-9]{64}$/', $coverageSha256) === 1
            && ($packageVerification['format'] ?? null) === 'biyahe-portable-package-verification-v1'
            && ($packageVerification['status'] ?? null) === 'PASS'
            && (int) ($packageVerification['files_verified'] ?? 0) === count($files)
            && hash_equals($manifestSha256, strtolower((string) ($packageVerification['manifest_sha256'] ?? '')))
            && hash_equals($coverageSha256, strtolower((string) ($packageManifest['journey_corpus']['sha256'] ?? '')))
            && hash_equals((string) ($packageManifest['repository']['core_commit'] ?? ''), (string) ($packageVerification['repository_core_commit'] ?? ''))
            && ($packageManifest['source_snapshot'] ?? null) === '2026-10-07T01:00:21Z'
            && ($packageManifest['artifacts']['graph']['fingerprint'] ?? null) === $expectedFingerprint
            && ($packageVerification['real_staging_executed'] ?? true) === false;
        $packageReady = $packageReady && $restrictionEvidenceComplete;
        foreach ($expected as $name => $hash) {
            $packageReady = $packageReady && hash_equals($hash, strtolower((string) ($manifestArtifacts[$name]['sha256'] ?? '')));
        }
        $verificationArtifacts = is_array($packageVerification['artifact_hashes'] ?? null) ? $packageVerification['artifact_hashes'] : [];
        foreach ($expected as $name => $hash) {
            $packageReady = $packageReady && hash_equals($hash, strtolower((string) ($verificationArtifacts[$name] ?? '')));
        }

        $validationStatus = static function (string $field) use ($validation): string {
            return is_string($validation[$field] ?? null) ? strtoupper($validation[$field]) : 'NOT_RUN';
        };
        $lintStatus = $validationStatus('php_lint');
        $bashStatus = $validationStatus('bash_syntax');
        $fixtureStatus = $validationStatus('fixture_tests');
        $importerCodePresent = ($validation['importer_code_present'] ?? false) === true;
        $importerStatus = ! $importerCodePresent || in_array('FAIL', [$lintStatus, $bashStatus, $fixtureStatus], true)
            ? 'FAIL'
            : (($lintStatus === 'PASS' && $bashStatus === 'PASS' && $fixtureStatus === 'PASS') ? 'PASS' : 'WARN');

        $v35Checks = [];
        foreach (($doctorV35['checks'] ?? []) as $check) {
            if (is_array($check) && is_string($check['name'] ?? null)) {
                $v35Checks[$check['name']] = $check;
            }
        }
        $doctorV35MatchesSnapshot = hash_equals($expected['source'], strtolower((string) ($doctorV35['source_sha256'] ?? '')))
            && hash_equals($expected['graph'], strtolower((string) ($doctorV35['graph_sha256'] ?? '')));
        $realStageWasValidated = $doctorV35MatchesSnapshot && ($v35Checks['real import completeness']['status'] ?? null) === 'PASS';
        $realStageStatus = $realStageWasValidated ? 'PASS' : 'BLOCKED';
        $realParityStatus = $doctorV35MatchesSnapshot && ($v35Checks['offline staged route parity']['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';
        $fieldStatus = $doctorV35MatchesSnapshot && ($v35Checks['field safety evidence']['status'] ?? null) === 'PASS' ? 'PASS' : 'BLOCKED';
        $privateBeta = $artifactIntegrity && $routeEngineReady && $coverageStatus === 'PASS'
            && $importerStatus === 'PASS' && $packageReady && $realStageStatus === 'PASS'
            && $realParityStatus === 'PASS' && $fieldStatus === 'PASS';

        $levels = [
            [
                'name' => 'offline_graph_artifact_integrity',
                'status' => $artifactIntegrity ? 'PASS' : 'FAIL',
                'evidence' => [
                    'source_sha256' => $sourceHash,
                    'graph_sha256' => $graphHash,
                    'graph_fingerprint' => $graphFingerprint,
                    'backup_sha256' => $backupHash,
                    'nodes' => $graph['nodes'] ?? null,
                    'edges' => $graph['edges'] ?? null,
                    'stored_restrictions' => $counts,
                    'source_restrictions' => [
                        'relations' => $packageRestrictions['source_relations'] ?? null,
                        'vehicle_only' => $packageRestrictions['vehicle_only'] ?? null,
                        'omitted_from_v3_storage' => $packageRestrictions['omitted_relations'] ?? null,
                        'generic_conditional' => $packageRestrictions['conditional'] ?? null,
                        'unsupported_applicable' => $packageRestrictions['unsupported_applicable'] ?? null,
                    ],
                ],
            ],
            [
                'name' => 'offline_route_engine_correctness',
                'status' => $routeEngineReady ? 'PASS' : 'BLOCKED',
                'evidence' => ['journey_cases' => $evaluated, 'routes_found' => $routeCounts['ROUTE_FOUND'], 'routes_unavailable' => $routeCounts['ROUTE_UNAVAILABLE'], 'snapshot_bound' => $routeEngineReady],
            ],
            [
                'name' => 'cebu_stop_place_coverage',
                'status' => $coverageStatus,
                'evidence' => ['snap_radius_meters' => $coverage['snap_policy']['maximum_distance_meters'] ?? null, 'stops' => $stopCoverage, 'places' => $placeCoverage, 'transfers' => $transfers],
            ],
            [
                'name' => 'importer_implementation_readiness',
                'status' => $importerStatus,
                'evidence' => ['php_lint' => $lintStatus, 'bash_syntax' => $bashStatus, 'fixture_tests' => $fixtureStatus, 'code_present' => $importerCodePresent, 'real_import_complete' => $realStageWasValidated],
            ],
            [
                'name' => 'portable_staging_package_readiness',
                'status' => $packageReady ? 'PASS' : 'FAIL',
                'evidence' => ['package_version' => $packageManifest['package_version'] ?? null, 'files_verified' => $packageVerification['files_verified'] ?? null, 'manifest_sha256' => $manifestSha256, 'core_commit' => $packageManifest['repository']['core_commit'] ?? null, 'database_started_by_verifier' => false],
            ],
            [
                'name' => 'real_postgresql_staging',
                'status' => $realStageStatus,
                'evidence' => ['status' => $v35Checks['real import completeness']['status'] ?? 'NOT_RUN', 'database_queried' => $v35Checks['real import completeness']['evidence']['database_queried'] ?? false],
            ],
            [
                'name' => 'real_route_parity',
                'status' => $realParityStatus,
                'evidence' => ['status' => $v35Checks['offline staged route parity']['status'] ?? 'NOT_RUN', 'staged_cases' => null],
            ],
            [
                'name' => 'field_verification',
                'status' => $fieldStatus,
                'evidence' => ['status' => $v35Checks['field safety evidence']['status'] ?? 'NOT_RUN', 'field_observations_complete' => false],
            ],
            [
                'name' => 'private_beta_readiness',
                'status' => $privateBeta ? 'PASS' : 'BLOCKED',
                'evidence' => ['all_release_gates_pass' => $privateBeta, 'transfer_locations_verified' => (int) ($transfers['records_marked_verified'] ?? 0), 'production_activation' => 'NO'],
            ],
        ];
        $overallStatus = in_array('FAIL', array_column($levels, 'status'), true) ? 'FAIL' : 'BLOCKED';

        return [
            'format' => 'biyahe-doctor-v36-static-v1',
            'overall_release_gate' => $overallStatus,
            'postgresql_contacted' => false,
            'production_activation' => 'NO',
            'readiness_levels' => $levels,
        ];
    }
}
