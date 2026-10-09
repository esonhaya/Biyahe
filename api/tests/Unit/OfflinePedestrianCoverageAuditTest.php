<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianCoverageAudit;
use Tests\Support\OfflinePedestrianGraphFixture;
use Tests\TestCase;

final class OfflinePedestrianCoverageAuditTest extends TestCase
{
    public function test_long_edge_review_case_uses_an_actual_sourced_graph_segment(): void
    {
        $path = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.8700, 1, null, null],
            [2, 10.3, 123.8730, 1, null, null],
        ], [
            [100, 0, 1, 2, 1, 328.0],
        ]);
        $transportData = [
            'backup_sha256' => str_repeat('b', 64),
            'stops' => [
                ['id' => 10, 'lat' => 10.3, 'lng' => 123.8700, 'verification_state' => 'VERIFIED', 'is_active' => true, 'is_demo' => false, 'city_id' => 1],
                ['id' => 11, 'lat' => 10.3, 'lng' => 123.8730, 'verification_state' => 'VERIFIED', 'is_active' => true, 'is_demo' => false, 'city_id' => 1],
            ],
            'places' => [],
            'transfers' => [],
        ];

        try {
            $report = (new OfflinePedestrianCoverageAudit(OfflinePedestrianGraphFixture::router($path)))
                ->audit($transportData, str_repeat('c', 64));
            $case = collect($report['journey_corpus']['cases'])->firstWhere('case_id', 'long_edge_review_journey');

            $this->assertSame('ROUTE_USES_REVIEWED_LONG_EDGE', $report['journey_corpus']['long_edge_review_case']['status']);
            $this->assertSame(100, $report['journey_corpus']['long_edge_review_case']['osm_way_id']);
            $this->assertSame('ROUTE_FOUND', $case['route']['status']);
            $this->assertSame(100, $case['route']['edges'][0]['way_id']);
            $this->assertTrue($case['geometry_valid']);
        } finally {
            OfflinePedestrianGraphFixture::remove($path);
        }
    }

    public function test_reports_stored_trust_snap_coverage_transfer_route_and_disconnected_real_record_pairs(): void
    {
        $path = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.8700, 1, null, null],
            [2, 10.3, 123.8703, 1, null, 'marked'],
            [3, 10.3, 123.8710, 1, null, null],
            [4, 10.3, 123.8713, 1, null, null],
        ], [
            [100, 0, 1, 2, 1, 32.9],
            [200, 0, 3, 4, 1, 32.9],
        ]);

        $transportData = [
            'backup_sha256' => str_repeat('b', 64),
            'database_snapshot' => 'verified backup fixture',
            'stops' => [
                ['id' => 10, 'lat' => 10.3, 'lng' => 123.8700, 'verification_state' => 'UNVERIFIED', 'boarding_verification_state' => 'UNVERIFIED', 'is_active' => true, 'is_demo' => false, 'is_boarding_point' => true, 'provenance_source_id' => 5, 'source_type' => 'COMMUNITY_OPEN_DATA', 'city_id' => 1],
                ['id' => 11, 'lat' => 10.3, 'lng' => 123.8703, 'verification_state' => 'VERIFIED', 'boarding_verification_state' => 'UNVERIFIED', 'is_active' => true, 'is_demo' => false, 'is_boarding_point' => true, 'provenance_source_id' => 5, 'source_type' => 'COMMUNITY_OPEN_DATA', 'city_id' => 1],
                ['id' => 12, 'lat' => 10.3, 'lng' => 123.8710, 'verification_state' => 'VERIFIED', 'boarding_verification_state' => 'VERIFIED', 'is_active' => true, 'is_demo' => false, 'is_boarding_point' => true, 'provenance_source_id' => 5, 'source_type' => 'COMMUNITY_OPEN_DATA', 'city_id' => 1],
                ['id' => 13, 'lat' => null, 'lng' => null, 'verification_state' => 'UNVERIFIED', 'boarding_verification_state' => 'UNVERIFIED', 'is_active' => true, 'is_demo' => false, 'is_boarding_point' => true, 'provenance_source_id' => null, 'source_type' => null, 'city_id' => 1],
            ],
            'places' => [
                ['id' => 20, 'lat' => 10.3, 'lng' => 123.8703, 'verification_state' => 'VERIFIED', 'is_active' => true, 'is_demo' => false, 'provenance_source_id' => 7, 'source_type' => 'COMMUNITY_OPEN_DATA', 'category' => 'MARKET', 'city_id' => 1],
                ['id' => 21, 'lat' => 10.3, 'lng' => 123.8713, 'verification_state' => 'DRAFT', 'is_active' => true, 'is_demo' => true, 'provenance_source_id' => 1, 'source_type' => 'DEMO_FIXTURE', 'category' => 'TEST', 'city_id' => 1],
            ],
            'transfers' => [
                ['id' => 30, 'from_stop_id' => 10, 'to_stop_id' => 11, 'verification_state' => 'UNVERIFIED', 'is_active' => true, 'is_demo' => false, 'provenance_source_id' => 5, 'source_type' => 'COMMUNITY_OPEN_DATA', 'relationship_type' => 'CURATED_WALK_ESTIMATE', 'distance_meters' => 33],
            ],
        ];

        try {
            $router = OfflinePedestrianGraphFixture::router($path);
            $report = (new OfflinePedestrianCoverageAudit($router))->audit($transportData, str_repeat('c', 64));
            $stopRecords = $report['coverage']['stops']['records'];
            $transfer = $report['coverage']['transfers']['records'][0];
            $disconnected = collect($report['journey_corpus']['cases'])->firstWhere('case_id', 'disconnected_component_journey');
            $sameComponent = collect($report['journey_corpus']['cases'])->firstWhere('case_id', 'same_component_journey');

            $this->assertSame(4, $report['coverage']['stops']['total']);
            $this->assertSame(3, $report['coverage']['stops']['with_usable_coordinates']);
            $this->assertSame(3, $report['coverage']['stops']['within_snap_policy']);
            $this->assertSame(2, $report['coverage']['stops']['connected_to_largest_component']);
            $this->assertSame(1, $report['coverage']['stops']['snapped_to_smaller_components']);
            $this->assertSame('UNVERIFIED_RECORD', $stopRecords[0]['trust_status']);
            $this->assertSame('VERIFIED_RECORD', $stopRecords[1]['trust_status']);
            $this->assertSame('ROUTE_FOUND', $transfer['graph_route_status']);
            $this->assertSame('UNVERIFIED_RECORD', $transfer['trust_status']);
            $this->assertSame('component_disconnected', $disconnected['route']['reason_code']);
            $this->assertSame('ROUTE_FOUND', $sameComponent['route']['status']);
            $this->assertTrue($sameComponent['geometry_valid']);
            $this->assertSame(['invalid_endpoint' => 1, 'snapped' => 3], $report['coverage']['stops']['snap_reason_counts']);
            $this->assertSame(0, $report['coverage']['transfers']['records_marked_verified']);
            $this->assertFalse($report['field_verification']['complete']);
        } finally {
            OfflinePedestrianGraphFixture::remove($path);
        }
    }
}
