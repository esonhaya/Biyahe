<?php

namespace Tests\Unit;

use App\Enums\PedestrianAccessClassification;
use App\Enums\PedestrianRouteStatus;
use App\Services\Routing\OfflinePedestrianRouteParityAudit;
use App\Services\Routing\PedestrianRouteResult;
use PHPUnit\Framework\TestCase;

final class OfflinePedestrianRouteParityAuditTest extends TestCase
{
    public function test_route_geometry_distance_snaps_and_estimate_must_match_the_staged_result(): void
    {
        $geometry = ['type' => 'LineString', 'coordinates' => [[123.87, 10.3], [123.8701, 10.3]]];
        $coverage = $this->coverage('ROUTE_FOUND', null, $geometry, 20.0, 17, 1, 2);
        $result = new PedestrianRouteResult(
            status: PedestrianRouteStatus::AVAILABLE,
            origin: ['lat' => 10.3, 'lng' => 123.87],
            destination: ['lat' => 10.3, 'lng' => 123.8701],
            accessClassification: PedestrianAccessClassification::NETWORK_ROUTE,
            distanceMeters: 20.0,
            estimatedDurationSeconds: 17,
            geometry: $geometry,
            originSnapDistanceMeters: 0,
            destinationSnapDistanceMeters: 0,
            originSnapNodeId: 1,
            destinationSnapNodeId: 2,
        );

        $report = (new OfflinePedestrianRouteParityAudit)->audit($coverage, static fn () => $result);

        $this->assertSame('PASS', $report['status']);
        $this->assertSame(1, $report['matches']);
        $this->assertSame(1, $report['reason_code_exact_matches']);
        $this->assertTrue($report['cases'][0]['geometry_matches_within_1e_7_degrees']);
        $this->assertTrue($report['cases'][0]['snapped_endpoints_match']);
    }

    public function test_disconnected_reason_is_normalized_without_claiming_raw_reason_code_identity(): void
    {
        $coverage = $this->coverage('ROUTE_UNAVAILABLE', 'component_disconnected', null, null, null, 1, 3);
        $result = new PedestrianRouteResult(
            status: PedestrianRouteStatus::NO_ROUTE,
            origin: ['lat' => 10.3, 'lng' => 123.87],
            destination: ['lat' => 10.3, 'lng' => 123.8701],
            reasonCode: 'path_unavailable',
            originSnapNodeId: 1,
            destinationSnapNodeId: 3,
        );

        $report = (new OfflinePedestrianRouteParityAudit)->audit($coverage, static fn () => $result);

        $this->assertSame('PASS', $report['status']);
        $this->assertSame(0, $report['reason_code_exact_matches']);
        $this->assertSame(1, $report['reason_family_matches']);
        $this->assertTrue($report['cases'][0]['reason_family_match']);
    }

    private function coverage(string $status, ?string $reason, ?array $geometry, ?float $distance, ?int $duration, int $originNode, int $destinationNode): array
    {
        return [
            'format' => 'biyahe-cebu-offline-coverage-v1',
            'complete' => true,
            'journey_corpus' => [
                'cases' => [[
                    'case_id' => 'synthetic-parity-case',
                    'status' => 'EVALUATED',
                    'maximum_distance_meters' => 100,
                    'source_records' => [
                        'origin' => ['coordinates' => ['lat' => 10.3, 'lng' => 123.87], 'snap' => ['node_id' => $originNode]],
                        'destination' => ['coordinates' => ['lat' => 10.3, 'lng' => 123.8701], 'snap' => ['node_id' => $destinationNode]],
                    ],
                    'route' => [
                        'status' => $status,
                        'reason_code' => $reason,
                        'geometry' => $geometry,
                        'distance_meters' => $distance,
                        'total_distance_with_approximate_snaps_meters' => $distance,
                        'estimated_duration_seconds' => $duration,
                    ],
                ]],
            ],
        ];
    }
}
