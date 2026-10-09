<?php

namespace App\Services\Routing;

use App\Enums\PedestrianRoutePurpose;
use InvalidArgumentException;

/** Compares stored source-backed Cebu journeys with an inactive staged graph. */
final class OfflinePedestrianRouteParityAudit
{
    /**
     * @param  callable(PedestrianRouteRequest): PedestrianRouteResult  $routeStaged
     * @return array<string, mixed>
     */
    public function audit(array $coverage, callable $routeStaged): array
    {
        if (($coverage['format'] ?? null) !== 'biyahe-cebu-offline-coverage-v1'
            || ($coverage['complete'] ?? false) !== true
            || ! is_array($coverage['journey_corpus']['cases'] ?? null)) {
            throw new InvalidArgumentException('Parity requires a complete Batch032 source-backed journey corpus.');
        }

        $cases = [];
        $elapsed = [];
        foreach ($coverage['journey_corpus']['cases'] as $case) {
            if (! is_array($case) || ($case['status'] ?? null) !== 'EVALUATED') {
                continue;
            }
            $records = $case['source_records'] ?? null;
            $origin = $records['origin']['coordinates'] ?? null;
            $destination = $records['destination']['coordinates'] ?? null;
            $maximumDistance = (float) ($case['maximum_distance_meters'] ?? 0);
            if (! is_array($origin) || ! is_array($destination) || $maximumDistance <= 0) {
                throw new InvalidArgumentException('A route corpus case has incomplete coordinates or distance bounds.');
            }

            $request = new PedestrianRouteRequest(
                ['lat' => (float) $origin['lat'], 'lng' => (float) $origin['lng']],
                ['lat' => (float) $destination['lat'], 'lng' => (float) $destination['lng']],
                PedestrianRoutePurpose::ACCESS_WALK,
                $maximumDistance,
            );
            $startedAt = hrtime(true);
            $actual = $routeStaged($request);
            $elapsedMilliseconds = round((hrtime(true) - $startedAt) / 1_000_000, 3);
            $elapsed[] = $elapsedMilliseconds;
            $expected = is_array($case['route'] ?? null) ? $case['route'] : [];
            $expectedFound = ($expected['status'] ?? null) === 'ROUTE_FOUND';
            $actualFound = $actual->status->isAvailable();
            $statusMatches = $expectedFound === $actualFound;
            $expectedReason = $expected['reason_code'] ?? null;
            $actualReason = $actual->reasonCode;
            $reasonExact = $expectedReason === $actualReason;
            $reasonFamilyMatches = $this->reasonFamily($expectedReason) === $this->reasonFamily($actualReason);

            $expectedOriginNode = $records['origin']['snap']['node_id'] ?? null;
            $expectedDestinationNode = $records['destination']['snap']['node_id'] ?? null;
            $snapsMatch = $expectedOriginNode === null || $expectedDestinationNode === null
                ? null
                : ((int) $expectedOriginNode === $actual->originSnapNodeId
                    && (int) $expectedDestinationNode === $actual->destinationSnapNodeId);
            $expectedDistance = isset($expected['distance_meters']) ? (float) $expected['distance_meters'] : null;
            $actualDistance = $actual->distanceMeters;
            $distanceMatches = $expectedFound && $actualFound
                ? $expectedDistance !== null && $actualDistance !== null && abs($expectedDistance - $actualDistance) <= 0.02
                : null;
            $expectedTotal = isset($expected['total_distance_with_approximate_snaps_meters'])
                ? (float) $expected['total_distance_with_approximate_snaps_meters'] : null;
            $actualTotal = $actual->totalDistanceMeters();
            $totalDistanceMatches = $expectedFound && $actualFound
                ? $expectedTotal !== null && $actualTotal !== null && abs($expectedTotal - $actualTotal) <= 0.02
                : null;
            $geometryMatches = $expectedFound && $actualFound
                ? $this->sameGeometry($expected['geometry'] ?? null, $actual->geometry)
                : null;
            $expectedDuration = $expected['estimated_duration_seconds'] ?? null;
            $durationMatches = $expectedFound && $actualFound
                ? $expectedDuration !== null && $actual->estimatedDurationSeconds === (int) $expectedDuration
                : null;
            $equivalent = $statusMatches && $reasonFamilyMatches
                && ($distanceMatches !== false) && ($totalDistanceMatches !== false)
                && ($geometryMatches !== false) && ($snapsMatch !== false)
                && ($durationMatches !== false);

            $cases[] = [
                'case_id' => (string) ($case['case_id'] ?? 'unknown'),
                'expected_status' => $expected['status'] ?? 'UNKNOWN',
                'actual_status' => $actual->status->value,
                'status_matches' => $statusMatches,
                'expected_reason_code' => $expectedReason,
                'actual_reason_code' => $actualReason,
                'reason_code_exact_match' => $reasonExact,
                'reason_family_match' => $reasonFamilyMatches,
                'expected_component_id' => $expected['component_id'] ?? null,
                'origin_snap_node_id_match' => $expectedOriginNode === null ? null : ((int) $expectedOriginNode === $actual->originSnapNodeId),
                'destination_snap_node_id_match' => $expectedDestinationNode === null ? null : ((int) $expectedDestinationNode === $actual->destinationSnapNodeId),
                'snapped_endpoints_match' => $snapsMatch,
                'expected_distance_meters' => $expectedDistance,
                'actual_distance_meters' => $actualDistance === null ? null : round($actualDistance, 2),
                'distance_matches_within_0_02m' => $distanceMatches,
                'expected_total_distance_meters' => $expectedTotal,
                'actual_total_distance_meters' => $actualTotal === null ? null : round($actualTotal, 2),
                'total_distance_matches_within_0_02m' => $totalDistanceMatches,
                'geometry_matches_within_1e_7_degrees' => $geometryMatches,
                'expected_duration_seconds' => $expectedDuration,
                'actual_duration_seconds' => $actual->estimatedDurationSeconds,
                'duration_matches' => $durationMatches,
                'duration_label' => $actualFound ? 'estimate_only' : null,
                'elapsed_milliseconds' => $elapsedMilliseconds,
                'equivalent' => $equivalent,
            ];
        }

        if ($cases === []) {
            throw new InvalidArgumentException('The route corpus contains no evaluated cases.');
        }
        $matched = count(array_filter($cases, static fn (array $case): bool => $case['equivalent']));
        $routesFound = count(array_filter($cases, static fn (array $case): bool => $case['actual_status'] === 'AVAILABLE' || $case['actual_status'] === 'PARTIAL'));
        sort($elapsed, SORT_NUMERIC);
        $reasonExactMatches = count(array_filter($cases, static fn (array $case): bool => $case['reason_code_exact_match']));
        $reasonFamilyMatches = count(array_filter($cases, static fn (array $case): bool => $case['reason_family_match']));

        return [
            'format' => 'biyahe-offline-staged-route-parity-v1',
            'status' => $matched === count($cases) ? 'PASS' : 'MISMATCH',
            'case_count' => count($cases),
            'matches' => $matched,
            'mismatches' => count($cases) - $matched,
            'routes_found' => $routesFound,
            'routes_unavailable' => count($cases) - $routesFound,
            'reason_code_exact_matches' => $reasonExactMatches,
            'reason_family_matches' => $reasonFamilyMatches,
            'distance_tolerance_meters' => 0.02,
            'geometry_coordinate_tolerance_degrees' => 0.0000001,
            'latency_milliseconds' => [
                'p50' => $this->percentile($elapsed, 0.50),
                'p95' => $this->percentile($elapsed, 0.95),
            ],
            'cases' => $cases,
        ];
    }

    private function reasonFamily(mixed $reason): string
    {
        return match ($reason) {
            null => 'ROUTE_FOUND',
            'component_disconnected', 'path_unavailable' => 'NO_PATH',
            'snap_unavailable' => 'SNAP_UNAVAILABLE',
            'endpoint_out_of_scope' => 'OUT_OF_SCOPE',
            'resource_budget_exceeded' => 'RESOURCE_LIMIT',
            'invalid_endpoint' => 'INVALID_ENDPOINT',
            default => strtoupper((string) $reason),
        };
    }

    private function sameGeometry(mixed $expected, ?array $actual): bool
    {
        if (! is_array($expected) || ! is_array($actual)
            || ($expected['type'] ?? null) !== 'LineString'
            || ($actual['type'] ?? null) !== 'LineString'
            || ! is_array($expected['coordinates'] ?? null)
            || ! is_array($actual['coordinates'] ?? null)
            || count($expected['coordinates']) !== count($actual['coordinates'])) {
            return false;
        }
        foreach ($expected['coordinates'] as $index => $expectedPoint) {
            $actualPoint = $actual['coordinates'][$index] ?? null;
            if (! is_array($expectedPoint) || ! is_array($actualPoint) || count($expectedPoint) < 2 || count($actualPoint) < 2
                || abs((float) $expectedPoint[0] - (float) $actualPoint[0]) > 0.0000001
                || abs((float) $expectedPoint[1] - (float) $actualPoint[1]) > 0.0000001) {
                return false;
            }
        }

        return true;
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }
        $index = max(0, (int) ceil(count($values) * $percentile) - 1);

        return round($values[$index], 3);
    }
}
