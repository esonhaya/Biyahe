<?php

namespace App\Services\Routing;

use Closure;
use JsonException;

/** Development-only adapter; it never changes transit legs or planner ranking. */
final class OfflineJourneyWalkingPreview
{
    private const WALKING_TYPES = ['WALK_ACCESS', 'TRANSFER_WALK', 'WALK_EGRESS'];

    private readonly ?Closure $resourceSampler;

    /** @param array<string, mixed>|null $settings */
    public function __construct(private readonly ?array $settings = null, ?callable $resourceSampler = null)
    {
        $this->resourceSampler = $resourceSampler === null ? null : Closure::fromCallable($resourceSampler);
    }

    /** @param array<string, mixed> $journey @return array{journey: array<string, mixed>, preview: array<string, mixed>} */
    public function preview(array $journey): array
    {
        $this->validateJourney($journey);
        $walkingCount = count(array_filter($journey['legs'], static fn (array $leg): bool => in_array($leg['type'], self::WALKING_TYPES, true)));
        $resourcePreflight = $walkingCount > 0 ? $this->requireResourceHeadroom() : null;
        $report = $this->verifiedReport();
        $router = $this->verifiedRouter($report);
        $routeWorkStarted = hrtime(true);
        $processedWalkingCount = 0;
        $foundCount = 0;

        foreach ($journey['legs'] as $index => $leg) {
            if (! is_array($leg) || ! in_array($leg['type'] ?? null, self::WALKING_TYPES, true)) {
                continue;
            }
            if (++$processedWalkingCount > (int) $this->setting('maximum_walking_legs')) {
                throw new OfflineWalkingPreviewException('resource_budget_exceeded', 'This development preview is limited to five walking legs.');
            }

            $origin = $this->location($leg['origin'] ?? null);
            $destination = $this->location($leg['destination'] ?? null);
            $this->assertRequestTimeWithinBudget($routeWorkStarted);
            $routeStarted = hrtime(true);
            $route = $router->route(
                $origin,
                $destination,
                (float) $this->setting('maximum_route_distance_meters'),
                (int) $this->setting('maximum_request_seconds') * 1_000,
            );
            if ((hrtime(true) - $routeStarted) > ((int) $this->setting('maximum_request_seconds') * 1_000_000_000)) {
                throw new OfflineWalkingPreviewException('resource_budget_exceeded', 'A bounded graph route exceeded the local preview time budget.');
            }
            try {
                OfflinePedestrianRouteGeometryContract::assertResult(
                    $route,
                    (string) $this->setting('source_sha256'),
                    (string) $this->setting('graph_fingerprint'),
                    (float) $this->setting('maximum_snap_distance_meters'),
                    (int) $this->setting('maximum_geometry_points'),
                );
            } catch (\InvalidArgumentException $exception) {
                throw new OfflineWalkingPreviewException('graph_unavailable', $exception->getMessage());
            }
            $journey['legs'][$index] = $this->applyRouteResult($leg, $route);
            $this->assertRequestTimeWithinBudget($routeWorkStarted);
            if (($route['status'] ?? null) === 'ROUTE_FOUND') {
                $foundCount++;
            }
        }

        $graphValidation = $report['graph_validation'];
        $preview = [
            'mode' => 'DEVELOPMENT_ONLY',
            'status' => $processedWalkingCount === 0 ? 'NO_WALKING_LEGS'
                : ($foundCount === 0 ? 'UNAVAILABLE' : ($foundCount === $processedWalkingCount ? 'COMPLETE' : 'PARTIAL')),
            'walking_legs' => $processedWalkingCount,
            'routes_found' => $foundCount,
            'source' => 'OPENSTREETMAP',
            'source_sha256' => (string) $this->setting('source_sha256'),
            'source_snapshot' => (string) $this->setting('source_snapshot'),
            'graph_sha256' => (string) $this->setting('graph_sha256'),
            'graph_fingerprint' => (string) $this->setting('graph_fingerprint'),
            'graph_format' => (string) $this->setting('graph_format'),
            'graph_schema_version' => (int) $this->setting('graph_schema_version'),
            'maximum_snap_distance_meters' => (int) $this->setting('maximum_snap_distance_meters'),
            'restriction_inventory' => $this->restrictionInventorySummary($report),
            'resource_preflight' => $resourcePreflight,
            'field_safety_verified' => false,
            'notice' => 'Development-only local graph preview. © OpenStreetMap contributors. It does not replace live directions or verify field access, crossings, transfers, or current conditions.',
        ];
        $journey['offline_walking_preview'] = $preview;

        return ['journey' => $journey, 'preview' => $preview];
    }

    /** Replays only the already stored Batch032 cases; it never opens or scans the source PBF. */
    public function verifyStoredCorpus(?array $requestedCaseIndices = null): array
    {
        $requestedCaseIndices = OfflineWalkingCorpusSelection::normalize($requestedCaseIndices);
        $resourcePreflight = $this->requireResourceHeadroom();
        $report = $this->verifiedReport();
        $router = $this->verifiedRouter($report);
        $cases = $report['journey_corpus']['cases'] ?? null;
        if (! is_array($cases) || count($cases) !== OfflineWalkingCorpusSelection::CASE_COUNT) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The pinned Cebu route corpus is missing or incomplete.');
        }

        $results = [];
        $startedAt = hrtime(true);
        $graphMetadata = $router->metadata();
        $caseIndices = $requestedCaseIndices ?? array_keys($cases);
        foreach ($caseIndices as $caseIndex) {
            $case = $cases[$caseIndex] ?? null;
            if (! is_array($case)) {
                throw new OfflineWalkingPreviewException('graph_unavailable', 'A requested Cebu corpus case is missing from the pinned report.');
            }
            $caseId = (string) ($case['case_id'] ?? '');
            $origin = $case['source_records']['origin']['coordinates'] ?? null;
            $destination = $case['source_records']['destination']['coordinates'] ?? null;
            $expected = $case['route'] ?? [];
            if ($caseId === '' || ! is_array($origin) || ! is_array($destination) || ! is_array($expected)) {
                throw new OfflineWalkingPreviewException('graph_unavailable', 'A stored Cebu corpus case has incomplete source coordinates or expected evidence.');
            }
            $maximumDistance = (float) ($case['maximum_distance_meters'] ?? 0);
            $caseStarted = hrtime(true);
            $actual = $router->route(
                $origin,
                $destination,
                $maximumDistance,
                (int) $this->setting('maximum_request_seconds') * 1_000,
            );
            try {
                OfflinePedestrianRouteGeometryContract::assertResult(
                    $actual,
                    (string) $this->setting('source_sha256'),
                    (string) $this->setting('graph_fingerprint'),
                    (float) $this->setting('maximum_snap_distance_meters'),
                    (int) $this->setting('maximum_geometry_points'),
                );
            } catch (\InvalidArgumentException $exception) {
                throw new OfflineWalkingPreviewException('graph_unavailable', $exception->getMessage());
            }
            $expectedStatus = (string) ($expected['status'] ?? '');
            $statusMatches = ($actual['status'] ?? null) === $expectedStatus;
            $reasonMatches = ($actual['reason_code'] ?? null) === ($expected['reason_code'] ?? null);
            $distanceMatches = $expectedStatus !== 'ROUTE_FOUND'
                || abs((float) ($actual['distance_meters'] ?? -1) - (float) ($expected['distance_meters'] ?? -2)) <= 0.02;
            $componentMatches = ($actual['component_id'] ?? null) === ($expected['component_id'] ?? null);
            $expectedCoordinates = $expected['geometry']['coordinates'] ?? null;
            $actualCoordinates = $actual['geometry']['coordinates'] ?? null;
            $geometryMatches = $expectedStatus !== 'ROUTE_FOUND' || $actualCoordinates === $expectedCoordinates;
            $directionMatches = $expectedStatus !== 'ROUTE_FOUND'
                || (($actual['node_ids'] ?? null) === ($expected['node_ids'] ?? null)
                    && $actualCoordinates === $expectedCoordinates);
            $actualEdgeIdentity = array_map(static fn (array $edge): array => [
                'way_id' => $edge['way_id'] ?? null,
                'segment_index' => $edge['segment_index'] ?? null,
                'length_meters' => $edge['length_meters'] ?? null,
            ], $actual['edges'] ?? []);
            $edgeIdentityMatches = $expectedStatus !== 'ROUTE_FOUND'
                || ($actualEdgeIdentity === ($expected['edges'] ?? null));
            $snapMatches = $expectedStatus !== 'ROUTE_FOUND'
                || (($actual['origin_snap']['node_id'] ?? null) === ($expected['origin_snap']['node_id'] ?? null)
                    && ($actual['destination_snap']['node_id'] ?? null) === ($expected['destination_snap']['node_id'] ?? null)
                    && abs((float) ($actual['origin_snap']['snap_distance_meters'] ?? -1000) - (float) ($expected['origin_snap']['snap_distance_meters'] ?? -2000)) <= 0.001
                    && abs((float) ($actual['destination_snap']['snap_distance_meters'] ?? -1000) - (float) ($expected['destination_snap']['snap_distance_meters'] ?? -2000)) <= 0.001);
            $geometryContinuous = true; // The shared route contract checked every coordinate, node, oriented edge and distance above.
            $results[] = [
                'case_index' => $caseIndex,
                'case_key' => sprintf('%02d:%s', $caseIndex, $caseId),
                'case_id' => $caseId,
                'origin_source_record' => [
                    'type' => $case['source_records']['origin']['type'] ?? null,
                    'id' => $case['source_records']['origin']['id'] ?? null,
                    'trust_status' => $case['source_records']['origin']['trust_status'] ?? null,
                ],
                'destination_source_record' => [
                    'type' => $case['source_records']['destination']['type'] ?? null,
                    'id' => $case['source_records']['destination']['id'] ?? null,
                    'trust_status' => $case['source_records']['destination']['trust_status'] ?? null,
                ],
                'expected_status' => $expectedStatus,
                'actual_status' => $actual['status'] ?? null,
                'expected_reason_code' => $expected['reason_code'] ?? null,
                'actual_reason_code' => $actual['reason_code'] ?? null,
                'status_matches' => $statusMatches,
                'reason_matches' => $reasonMatches,
                'distance_matches_within_0_02_meters' => $distanceMatches,
                'component_matches' => $componentMatches,
                'geometry_matches_stored_route' => $geometryMatches,
                'geometry_direction_matches' => $directionMatches,
                'edge_identity_matches' => $edgeIdentityMatches,
                'snapped_endpoints_match' => $snapMatches,
                'geometry_continuous' => $geometryContinuous,
                'distance_meters' => $actual['distance_meters'] ?? null,
                'component_id' => $actual['component_id'] ?? null,
                'origin_snap_node_id' => $actual['origin_snap']['node_id'] ?? null,
                'destination_snap_node_id' => $actual['destination_snap']['node_id'] ?? null,
                'origin_snap_coordinate' => isset($actual['origin_snap']['node_longitude'], $actual['origin_snap']['node_latitude'])
                    ? [$actual['origin_snap']['node_longitude'], $actual['origin_snap']['node_latitude']] : null,
                'destination_snap_coordinate' => isset($actual['destination_snap']['node_longitude'], $actual['destination_snap']['node_latitude'])
                    ? [$actual['destination_snap']['node_longitude'], $actual['destination_snap']['node_latitude']] : null,
                'geometry_points' => is_array($actualCoordinates) ? count($actualCoordinates) : 0,
                'geometry' => $actual['geometry'] ?? null,
                'node_ids' => $actual['node_ids'] ?? [],
                'edges' => $actual['edges'] ?? [],
                'origin_snap_distance_meters' => $actual['origin_snap']['snap_distance_meters'] ?? null,
                'destination_snap_distance_meters' => $actual['destination_snap']['snap_distance_meters'] ?? null,
                'failure_has_no_path_geometry' => ($actual['status'] ?? null) !== 'ROUTE_UNAVAILABLE'
                    || (($actual['geometry'] ?? null) === null && ($actual['node_ids'] ?? []) === [] && ($actual['edges'] ?? []) === []),
                'elapsed_milliseconds' => round((hrtime(true) - $caseStarted) / 1_000_000, 3),
            ];
        }
        $passed = count(array_filter($results, static fn (array $case): bool => $case['status_matches']
            && $case['reason_matches'] && $case['distance_matches_within_0_02_meters'] && $case['component_matches']
            && $case['geometry_matches_stored_route'] && $case['geometry_direction_matches']
            && $case['edge_identity_matches'] && $case['snapped_endpoints_match']
            && $case['geometry_continuous'])) === count($results);

        $completeCorpus = OfflineWalkingCorpusSelection::isComplete($requestedCaseIndices);

        return [
            'format' => 'biyahe-offline-walking-corpus-v1',
            'status' => $passed ? ($completeCorpus ? 'PASS' : 'PARTIAL') : 'FAIL',
            'source_sha256' => (string) $this->setting('source_sha256'),
            'source_sha256_recomputed' => false,
            'graph_sha256' => (string) $this->setting('graph_sha256'),
            'graph_fingerprint' => (string) $this->setting('graph_fingerprint'),
            'graph_format' => (string) ($graphMetadata['format'] ?? ''),
            'graph_schema_version' => (int) ($graphMetadata['schema_version'] ?? 0),
            'resource_preflight' => $resourcePreflight,
            'maximum_snap_distance_meters' => (float) $this->setting('maximum_snap_distance_meters'),
            'cases_total' => count($results),
            'cases_expected_total' => count($cases),
            'case_indices' => $caseIndices,
            'complete_corpus' => $completeCorpus,
            'routes_found' => count(array_filter($results, static fn (array $case): bool => $case['actual_status'] === 'ROUTE_FOUND')),
            'routes_unavailable' => count(array_filter($results, static fn (array $case): bool => $case['actual_status'] === 'ROUTE_UNAVAILABLE')),
            'status_matches' => count(array_filter($results, static fn (array $case): bool => $case['status_matches'])),
            'reason_matches' => count(array_filter($results, static fn (array $case): bool => $case['reason_matches'])),
            'geometry_matches' => count(array_filter($results, static fn (array $case): bool => $case['geometry_matches_stored_route'])),
            'direction_matches' => count(array_filter($results, static fn (array $case): bool => $case['geometry_direction_matches'])),
            'edge_identity_matches' => count(array_filter($results, static fn (array $case): bool => $case['edge_identity_matches'])),
            'snapped_endpoints_match' => count(array_filter($results, static fn (array $case): bool => $case['snapped_endpoints_match'])),
            'distance_matches' => count(array_filter($results, static fn (array $case): bool => $case['distance_matches_within_0_02_meters'])),
            'elapsed_milliseconds' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
            'cases' => $results,
        ];
    }

    /** @param array<string, mixed> $journey */
    private function validateJourney(array $journey): void
    {
        if (! is_string($journey['id'] ?? null) || trim($journey['id']) === ''
            || ! is_array($journey['legs'] ?? null) || count($journey['legs']) < 1 || count($journey['legs']) > 12) {
            throw new OfflineWalkingPreviewException('invalid_journey', 'A journey ID and one to twelve ordered legs are required.');
        }
        foreach ($journey['legs'] as $leg) {
            if (! is_array($leg) || ! is_string($leg['type'] ?? null)
                || ! in_array($leg['type'], [...self::WALKING_TYPES, 'RIDE'], true)) {
                throw new OfflineWalkingPreviewException('invalid_journey', 'Journey legs must use the existing Biyahe leg types.');
            }
        }
    }

    /** @param array<string, mixed>|null $point @return array{lat: float, lng: float} */
    private function location(mixed $point): array
    {
        $location = is_array($point) ? ($point['location'] ?? null) : null;
        if (! is_array($location) || ! isset($location['lat'], $location['lng'])
            || ! is_numeric($location['lat']) || ! is_numeric($location['lng'])) {
            return ['lat' => NAN, 'lng' => NAN];
        }

        return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
    }

    /** @param array<string, mixed> $leg @param array<string, mixed> $route @return array<string, mixed> */
    private function applyRouteResult(array $leg, array $route): array
    {
        $found = ($route['status'] ?? null) === 'ROUTE_FOUND';
        $approximateConnectors = $found && (float) ($route['total_distance_with_approximate_snaps_meters'] ?? 0)
            > (float) ($route['distance_meters'] ?? 0);
        $leg['distance_meters'] = $found ? $route['total_distance_with_approximate_snaps_meters'] : null;
        $leg['distance_is_estimate'] = $found && $approximateConnectors;
        $leg['distance_type'] = $found ? ($approximateConnectors
            ? 'PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS'
            : 'PEDESTRIAN_NETWORK') : 'UNAVAILABLE';
        $leg['network_distance_meters'] = $found ? $route['distance_meters'] : null;
        $leg['approximate_connector_distance_meters'] = $found
            ? round(max(0.0, (float) $route['total_distance_with_approximate_snaps_meters'] - (float) $route['distance_meters']), 3)
            : null;
        $leg['distance_provenance'] = $found
            ? ($approximateConnectors ? 'OSM_NETWORK_PLUS_APPROXIMATE_ENDPOINT_CONNECTORS' : 'OSM_NETWORK_WITHIN_SNAP_TOLERANCE')
            : 'UNAVAILABLE';
        $leg['geometry_provenance'] = $found ? 'OPENSTREETMAP_GRAPH_EDGE_COORDINATES' : 'UNAVAILABLE';
        $leg['walking_source_snapshot'] = (string) $this->setting('source_snapshot');
        $leg['routing_status'] = $found ? 'ROUTE_FOUND' : 'ROUTE_UNAVAILABLE';
        $leg['routing_source'] = 'OPENSTREETMAP_OFFLINE_PREVIEW';
        $leg['access_classification'] = $found ? 'NETWORK_ROUTE' : $this->accessClassFor((string) ($route['reason_code'] ?? 'graph_unavailable'));
        $leg['estimated_duration_seconds'] = $found ? $route['estimated_duration_seconds'] : null;
        $leg['geometry'] = $found ? $route['geometry'] : null;
        $leg['warnings'] = array_values(array_unique([
            ...array_filter(is_array($leg['warnings'] ?? null) ? $leg['warnings'] : [], 'is_string'),
            ...($found ? $route['warnings'] : ['No local graph walking path was established; no substitute line or connector is shown.']),
        ]));
        $leg['offline_walking_preview'] = [
            'status' => $found ? 'ROUTE_FOUND' : 'ROUTE_UNAVAILABLE',
            'reason_code' => $route['reason_code'] ?? null,
            'source' => 'OPENSTREETMAP',
            'graph_sha256' => (string) $this->setting('graph_sha256'),
            'graph_fingerprint' => (string) $this->setting('graph_fingerprint'),
            'component_id' => $route['component_id'] ?? null,
            'origin_snap_distance_meters' => $route['origin_snap']['snap_distance_meters'] ?? null,
            'destination_snap_distance_meters' => $route['destination_snap']['snap_distance_meters'] ?? null,
            'duration_label' => $found ? 'estimate_only' : null,
            'field_safety_verified' => false,
        ];

        return $leg;
    }

    private function accessClassFor(string $reason): string
    {
        return match ($reason) {
            'snap_unavailable', 'endpoint_out_of_scope' => 'GRAPH_COVERAGE_GAP',
            'component_disconnected' => 'NO_WALKABLE_ACCESS',
            default => 'ROUTING_UNAVAILABLE',
        };
    }

    /** @return array{samples: int, minimum_memavailable_kib: int, minimum_swapfree_kib: int} */
    private function requireResourceHeadroom(): array
    {
        $minimumMemory = PHP_INT_MAX;
        $minimumSwap = PHP_INT_MAX;
        for ($sampleIndex = 0; $sampleIndex < 3; $sampleIndex++) {
            $sample = $this->resourceSampler === null ? $this->readResourceMetrics() : ($this->resourceSampler)();
            $memory = $sample['memavailable_kib'] ?? null;
            $swap = $sample['swapfree_kib'] ?? null;
            if (! is_int($memory) || ! is_int($swap) || $memory < 524_288 || $swap < 131_072) {
                throw new OfflineWalkingPreviewException('resource_budget_exceeded', 'The local graph preview needs three resource samples at or above 512 MiB MemAvailable and 128 MiB SwapFree.');
            }
            $minimumMemory = min($minimumMemory, $memory);
            $minimumSwap = min($minimumSwap, $swap);
            if ($sampleIndex < 2 && $this->resourceSampler === null) {
                usleep(1_000_000);
            }
        }

        return [
            'samples' => 3,
            'minimum_memavailable_kib' => $minimumMemory,
            'minimum_swapfree_kib' => $minimumSwap,
        ];
    }

    /** @return array{memavailable_kib: int|null, swapfree_kib: int|null} */
    private function readResourceMetrics(): array
    {
        $contents = @file_get_contents('/proc/meminfo');
        if (! is_string($contents)) {
            return ['memavailable_kib' => null, 'swapfree_kib' => null];
        }
        preg_match('/^MemAvailable:\\s+(\\d+)\\s+kB$/m', $contents, $memoryMatch);
        preg_match('/^SwapFree:\\s+(\\d+)\\s+kB$/m', $contents, $swapMatch);

        return [
            'memavailable_kib' => isset($memoryMatch[1]) ? (int) $memoryMatch[1] : null,
            'swapfree_kib' => isset($swapMatch[1]) ? (int) $swapMatch[1] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function verifiedReport(): array
    {
        $path = (string) $this->setting('coverage_report_path');
        $expectedHash = strtolower((string) $this->setting('coverage_report_sha256'));
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5_000_000) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The committed graph coverage evidence is unavailable.');
        }
        $actualHash = hash_file('sha256', $path);
        if (! is_string($actualHash) || ! preg_match('/^[a-f0-9]{64}$/', $expectedHash)
            || ! hash_equals($expectedHash, strtolower($actualHash))) {
            throw new OfflineWalkingPreviewException('source_mismatch', 'The graph coverage evidence does not match its pinned fingerprint.');
        }
        try {
            $report = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The graph coverage evidence is not valid JSON.');
        }
        if (! is_array($report)) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The graph coverage evidence must contain an object.');
        }
        $source = $report['source'] ?? null;
        $snapPolicy = $report['snap_policy'] ?? null;
        $graph = $report['graph_validation'] ?? null;
        if (! is_array($source) || ! is_array($snapPolicy) || ! is_array($graph)) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The graph coverage evidence is incomplete.');
        }
        $capabilities = $graph['routing_capabilities'] ?? [];
        $restrictionCounts = $graph['restriction_counts'] ?? [];
        if (($report['format'] ?? null) !== 'biyahe-cebu-offline-coverage-v1' || ($report['complete'] ?? false) !== true) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The stored graph evidence is incomplete or uses an unsupported format.');
        }
        if (($source['graph_source_sha256'] ?? null) !== $this->setting('source_sha256')
            || ($graph['artifact_sha256'] ?? null) !== $this->setting('graph_sha256')
            || ($graph['graph_fingerprint'] ?? null) !== $this->setting('graph_fingerprint')) {
            throw new OfflineWalkingPreviewException('source_mismatch', 'The stored graph evidence is bound to a different source snapshot or artifact.');
        }
        if ((int) ($snapPolicy['maximum_distance_meters'] ?? 0) !== 25
            || ($snapPolicy['virtual_edge_anchors'] ?? true) !== false) {
            throw new OfflineWalkingPreviewException('unsupported_routing_capability', 'The stored graph evidence does not use the required 25 m snap policy without virtual anchors.');
        }
        if (! is_array($capabilities) || ! is_array($restrictionCounts)) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The graph restriction evidence is incomplete.');
        }
        $maximumRoute = (float) $this->setting('maximum_route_distance_meters');
        if ((float) $this->setting('maximum_snap_distance_meters') !== 25.0
            || ! is_finite($maximumRoute) || $maximumRoute <= 0 || $maximumRoute > 2_000
            || (int) $this->setting('maximum_walking_legs') < 1 || (int) $this->setting('maximum_walking_legs') > 5
            || (int) $this->setting('maximum_geometry_points') < 2 || (int) $this->setting('maximum_geometry_points') > 2_048
            || (int) $this->setting('maximum_request_seconds') < 1 || (int) $this->setting('maximum_request_seconds') > 8
            || (int) $this->setting('maximum_request_bytes') < 1 || (int) $this->setting('maximum_request_bytes') > 2_000_000
            || (int) $this->setting('maximum_response_bytes') < 1 || (int) $this->setting('maximum_response_bytes') > 2_000_000) {
            throw new OfflineWalkingPreviewException('unsupported_routing_capability', 'The local preview resource limits do not match the supported bounded policy.');
        }
        foreach (['nodes' => 'graph_nodes', 'edges' => 'graph_edges', 'restriction_relations' => 'restriction_relations'] as $reportKey => $settingKey) {
            if ((int) ($graph[$reportKey] ?? -1) !== (int) $this->setting($settingKey)) {
                throw new OfflineWalkingPreviewException('source_mismatch', 'The graph coverage evidence row counts do not match the pinned artifact.');
            }
        }
        if ((int) ($restrictionCounts['total'] ?? -1) !== (int) $this->setting('restriction_relations')) {
            throw new OfflineWalkingPreviewException('source_mismatch', 'The graph restriction inventory count does not match the pinned artifact.');
        }
        if (($graph['artifact_ready'] ?? false) !== true || ($graph['routing_ready'] ?? false) !== true
            || ($graph['restriction_inventory_complete'] ?? false) !== true
            || ($capabilities['complete_restriction_inventory'] ?? false) !== true
            || ($capabilities['routing_ready'] ?? false) !== true
            || ($capabilities['turn_restrictions_needed'] ?? true) !== false
            || (int) ($restrictionCounts['pedestrian_applicable'] ?? -1) !== 0
            || (int) ($restrictionCounts['ambiguous'] ?? -1) !== 0
            || (int) ($restrictionCounts['unsupported_applicable'] ?? -1) !== 0) {
            throw new OfflineWalkingPreviewException('unsupported_routing_capability', 'The stored graph evidence does not establish this snapshot as safe for pedestrian routing.');
        }

        return $report;
    }

    /** @param array<string, mixed> $report */
    private function verifiedRouter(array $report): OfflinePedestrianGraphRouter
    {
        $path = (string) $this->setting('graph_path');
        $expectedHash = strtolower((string) $this->setting('graph_sha256'));
        if (! is_file($path) || ! is_readable($path)) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The local SQLite pedestrian graph is unavailable.');
        }
        $actualHash = hash_file('sha256', $path);
        if (! is_string($actualHash) || ! hash_equals($expectedHash, strtolower($actualHash))) {
            throw new OfflineWalkingPreviewException('source_mismatch', 'The local SQLite graph does not match the verified snapshot hash.');
        }

        $validation = $report['graph_validation'];
        $validation['source_sha256'] = (string) $this->setting('source_sha256');
        $router = new OfflinePedestrianGraphRouter(
            $path,
            (string) $this->setting('source_sha256'),
            $validation,
            (float) $this->setting('maximum_snap_distance_meters'),
            50_000,
            20_000,
            1.2,
        );
        $metadata = $router->metadata();
        if ($metadata === [] || ($metadata['state'] ?? null) !== 'COMPLETE'
            || ($metadata['input_complete'] ?? false) !== true) {
            throw new OfflineWalkingPreviewException('graph_unavailable', 'The SQLite file is not a readable complete pedestrian graph.');
        }
        if (($metadata['format'] ?? null) !== $this->setting('graph_format')
            || (int) ($metadata['schema_version'] ?? 0) !== (int) $this->setting('graph_schema_version')) {
            throw new OfflineWalkingPreviewException('unsupported_routing_capability', 'The SQLite graph format or schema version is not supported by this preview.');
        }
        if (($metadata['source_sha256'] ?? null) !== $this->setting('source_sha256')
            || ($metadata['graph_content_sha256'] ?? null) !== $this->setting('graph_fingerprint')) {
            throw new OfflineWalkingPreviewException('source_mismatch', 'The SQLite graph metadata does not match the pinned source and graph fingerprints.');
        }
        foreach (['graph_nodes' => 'graph_nodes', 'graph_edges' => 'graph_edges', 'relation_restrictions' => 'restriction_relations'] as $metadataKey => $settingKey) {
            if ((int) ($metadata[$metadataKey] ?? -1) !== (int) $this->setting($settingKey)) {
                throw new OfflineWalkingPreviewException('source_mismatch', 'The SQLite graph row or restriction counts do not match the pinned artifact.');
            }
        }

        return $router;
    }

    private function assertRequestTimeWithinBudget(int $startedAt): void
    {
        if ((hrtime(true) - $startedAt) > ((int) $this->setting('maximum_request_seconds') * 1_000_000_000)) {
            throw new OfflineWalkingPreviewException('resource_budget_exceeded', 'The local graph preview exceeded its bounded request time.');
        }
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function restrictionInventorySummary(array $report): array
    {
        $graph = $report['graph_validation'];
        $counts = $graph['restriction_counts'];

        return [
            'complete' => true,
            'source_relation_count' => (int) $graph['restriction_relations'],
            'pedestrian_applicable' => (int) $counts['pedestrian_applicable'],
            'ambiguous' => (int) $counts['ambiguous'],
            'unsupported_applicable' => (int) $counts['unsupported_applicable'],
            'conditional_relations' => (int) ($counts['conditional'] ?? 0),
            'conditional_semantics_evaluated' => (bool) ($graph['routing_capabilities']['conditional_turn_restrictions_evaluated'] ?? false),
            'state_aware_turn_search' => (bool) ($graph['routing_capabilities']['state_aware_search'] ?? false),
            'policy' => 'This snapshot has no pedestrian-applicable or ambiguous turn restriction; conditional vehicle restrictions are not evaluated for pedestrian routing.',
        ];
    }

    private function setting(string $key): mixed
    {
        $settings = $this->settings ?? config('offline_walking_preview', []);

        return $settings[$key] ?? null;
    }
}
