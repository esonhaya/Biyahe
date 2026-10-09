<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianGraphReadinessV36;
use PHPUnit\Framework\TestCase;

final class OfflinePedestrianGraphReadinessV36Test extends TestCase
{
    public function test_package_can_pass_while_real_staging_and_beta_remain_blocked(): void
    {
        $manifest = $this->manifest();
        $manifestSha = str_repeat('c', 64);
        $coverageSha = str_repeat('d', 64);
        $report = (new OfflinePedestrianGraphReadinessV36)->build(
            $this->coverage(),
            $this->doctorV35(),
            $manifest,
            ['format' => 'biyahe-portable-package-verification-v1', 'status' => 'PASS', 'files_verified' => count($manifest['files']), 'manifest_sha256' => $manifestSha, 'repository_core_commit' => $manifest['repository']['core_commit'], 'artifact_hashes' => ['source' => '787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d', 'graph' => '29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8', 'backup' => 'e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92'], 'restriction_inventory' => $manifest['restriction_inventory'], 'real_staging_executed' => false],
            ['php_lint' => 'PASS', 'bash_syntax' => 'PASS', 'fixture_tests' => 'BLOCKED', 'importer_code_present' => true],
            $manifestSha,
            $coverageSha,
        );

        $levels = array_column($report['readiness_levels'], null, 'name');
        $this->assertSame('BLOCKED', $report['overall_release_gate']);
        $this->assertFalse($report['postgresql_contacted']);
        $this->assertSame('PASS', $levels['offline_graph_artifact_integrity']['status']);
        $this->assertSame('PASS', $levels['offline_route_engine_correctness']['status']);
        $this->assertSame('WARN', $levels['cebu_stop_place_coverage']['status']);
        $this->assertSame('WARN', $levels['importer_implementation_readiness']['status']);
        $this->assertSame('PASS', $levels['portable_staging_package_readiness']['status']);
        $this->assertSame('BLOCKED', $levels['real_postgresql_staging']['status']);
        $this->assertSame('BLOCKED', $levels['real_route_parity']['status']);
        $this->assertSame('BLOCKED', $levels['field_verification']['status']);
        $this->assertSame('BLOCKED', $levels['private_beta_readiness']['status']);
    }

    public function test_tampered_package_verification_and_incomplete_route_corpus_fail_closed(): void
    {
        $manifest = $this->manifest();
        $manifestSha = str_repeat('c', 64);
        $coverageSha = str_repeat('d', 64);
        $coverage = $this->coverage();
        array_pop($coverage['journey_corpus']['cases']);
        $report = (new OfflinePedestrianGraphReadinessV36)->build(
            $coverage,
            $this->doctorV35(),
            $manifest,
            ['format' => 'biyahe-portable-package-verification-v1', 'status' => 'PASS', 'files_verified' => 0, 'manifest_sha256' => str_repeat('d', 64), 'real_staging_executed' => false],
            ['php_lint' => 'PASS', 'bash_syntax' => 'PASS', 'fixture_tests' => 'PASS', 'importer_code_present' => true],
            $manifestSha,
            $coverageSha,
        );
        $levels = array_column($report['readiness_levels'], null, 'name');

        $this->assertSame('BLOCKED', $report['overall_release_gate']);
        $this->assertSame('BLOCKED', $levels['offline_route_engine_correctness']['status']);
        $this->assertSame('FAIL', $levels['portable_staging_package_readiness']['status']);
        $this->assertSame('BLOCKED', $levels['private_beta_readiness']['status']);
    }

    private function manifest(): array
    {
        return [
            'format' => 'biyahe-portable-pedestrian-staging-v1',
            'package_version' => 1,
            'repository' => ['core_commit' => str_repeat('a', 40)],
            'artifacts' => [
                'source' => ['sha256' => '787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d'],
                'graph' => ['sha256' => '29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8', 'fingerprint' => 'a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd'],
                'backup' => ['sha256' => 'e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92'],
            ],
            'source_snapshot' => '2026-10-07T01:00:21Z',
            'journey_corpus' => ['sha256' => str_repeat('d', 64)],
            'restriction_inventory' => $this->restrictionInventory(),
            'files' => ['evidence/batch030-cebu-restriction-audit.json' => str_repeat('b', 64), 'two' => str_repeat('c', 64)],
        ];
    }

    private function restrictionInventory(): array
    {
        return [
            'audit_filename' => 'evidence/batch030-cebu-restriction-audit.json',
            'audit_sha256' => str_repeat('b', 64),
            'source_scan_complete' => true,
            'producer_exit_code' => 0,
            'source_relations' => 648,
            'stored_relations' => 619,
            'omitted_relations' => 29,
            'vehicle_only' => 648,
            'pedestrian_applicable' => 0,
            'pedestrian_exempt' => 0,
            'ambiguous' => 0,
            'unsupported_applicable' => 0,
            'conditional' => 33,
            'via_node' => 449,
            'via_way' => 166,
            'malformed_or_complex' => 4,
            'unresolved_member_references' => 75,
        ];
    }

    private function coverage(): array
    {
        $cases = [];
        for ($index = 0; $index < 10; $index++) {
            $cases[] = ['status' => 'EVALUATED', 'route' => ['status' => $index === 6 ? 'ROUTE_UNAVAILABLE' : 'ROUTE_FOUND']];
        }

        return [
            'format' => 'biyahe-cebu-offline-coverage-v1',
            'complete' => true,
            'source' => [
                'graph_source_sha256' => '787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d',
                'graph_fingerprint' => 'a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd',
                'transport_backup_sha256' => 'e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92',
            ],
            'snap_policy' => ['maximum_distance_meters' => 25],
            'graph_validation' => [
                'artifact_sha256' => '29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8',
                'artifact_ready' => true,
                'routing_ready' => true,
                'restriction_inventory_complete' => true,
                'nodes' => 76685,
                'edges' => 78225,
                'restriction_counts' => ['total' => 619, 'vehicle_only' => 619, 'pedestrian_applicable' => 0, 'ambiguous' => 0, 'unsupported_applicable' => 0],
            ],
            'journey_corpus' => ['cases' => $cases],
            'coverage' => [
                'stops' => ['total' => 503, 'within_snap_policy' => 436, 'unreachable_or_unsnappable' => 70],
                'places' => ['total' => 37, 'within_snap_policy' => 30, 'unreachable_or_unsnappable' => 8],
                'transfers' => ['total' => 2, 'records_marked_verified' => 0, 'graph_route_connected' => 2],
            ],
        ];
    }

    private function doctorV35(): array
    {
        return [
            'source_sha256' => '787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d',
            'graph_sha256' => '29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8',
            'checks' => [
                ['name' => 'real import completeness', 'status' => 'BLOCKED', 'evidence' => ['database_queried' => false]],
                ['name' => 'offline staged route parity', 'status' => 'BLOCKED'],
                ['name' => 'field safety evidence', 'status' => 'BLOCKED'],
            ],
        ];
    }
}
