<?php

namespace App\Services\Routing;

use InvalidArgumentException;

/** Audits sourced transport coordinates against the immutable offline graph. */
final class OfflinePedestrianCoverageAudit
{
    public function __construct(private readonly OfflinePedestrianGraphRouter $router) {}

    /** @return array<string, mixed> */
    public function audit(array $transportData, string $recordsSha256): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/i', $recordsSha256)
            || ! preg_match('/^[a-f0-9]{64}$/i', (string) ($transportData['backup_sha256'] ?? ''))
            || ! is_array($transportData['stops'] ?? null)
            || ! is_array($transportData['places'] ?? null)
            || ! is_array($transportData['transfers'] ?? null)) {
            throw new InvalidArgumentException('Transport coverage input must contain sourced stop, place, and transfer records with verified backup provenance.');
        }

        $stops = $this->endpoints('stop', $transportData['stops']);
        $places = $this->endpoints('place', $transportData['places']);
        $stopsById = [];
        foreach ($stops as $stop) {
            $stopsById[$stop['id']] = $stop;
        }
        $stopSummary = $this->endpointSummary($stops);
        $placeSummary = $this->endpointSummary($places);
        $transfers = $this->auditTransfers($transportData['transfers'], $stopsById);
        $corpus = $this->journeyCorpus($stops, $places, $transfers);
        $componentSummary = $this->router->componentSummary();

        return [
            'format' => 'biyahe-cebu-offline-coverage-v1',
            'source' => [
                'graph_source_sha256' => (string) ($this->router->metadata()['source_sha256'] ?? ''),
                'graph_fingerprint' => (string) ($this->router->metadata()['graph_content_sha256'] ?? ''),
                'transport_backup_sha256' => strtolower((string) $transportData['backup_sha256']),
                'transport_records_sha256' => strtolower($recordsSha256),
                'transport_snapshot_label' => (string) ($transportData['database_snapshot'] ?? 'unspecified verified database snapshot'),
                'provenance_limits' => [
                    'The backup contains application record provenance and verification states; it is not independent field verification.',
                    'Community-open-data stop and place records remain at their recorded trust level.',
                ],
            ],
            'snap_policy' => [
                'maximum_distance_meters' => 25,
                'candidate_selection' => 'nearest walkable OSM node; distance then OSM node ID tie-break',
                'candidate_count' => 1,
                'barrier_rule' => 'reject the approximate endpoint connector if it passes within 1.5 m of a mapped blocked barrier node',
                'virtual_edge_anchors' => false,
                'connector_geometry_included_in_network_geometry' => false,
                'unknown_unmapped_barriers' => 'not detected; field access remains unverified',
            ],
            'coverage' => [
                'stops' => $stopSummary,
                'places' => $placeSummary,
                'transfers' => [
                    'total' => count($transfers),
                    'records_marked_verified' => count(array_filter($transfers, static fn (array $transfer): bool => $transfer['trust_status'] === 'VERIFIED_RECORD')),
                    'graph_route_connected' => count(array_filter($transfers, static fn (array $transfer): bool => $transfer['graph_route_status'] === 'ROUTE_FOUND')),
                    'graph_route_unavailable' => count(array_filter($transfers, static fn (array $transfer): bool => $transfer['graph_route_status'] !== 'ROUTE_FOUND')),
                    'records' => $transfers,
                    'interpretation' => 'Graph connectivity does not verify a transfer relationship or a safe physical interchange.',
                ],
            ],
            'journey_corpus' => $corpus,
            'quality_summary' => [
                'walkable_components' => $componentSummary['components'],
                'largest_walkable_component_nodes' => $componentSummary['largest_component_nodes'],
                'long_edges_over_250m' => (int) ($this->router->metadata()['quality_review']['long_edges_over_250m'] ?? 0),
                'boundary_nodes_within_0_0005_degrees' => (int) ($this->router->metadata()['quality_review']['boundary_nodes_within_0_0005_degrees'] ?? 0),
                'crossing_tagged_nodes' => (int) ($this->router->metadata()['quality_review']['crossing_tagged_nodes'] ?? 0),
                'field_review_required' => true,
            ],
            'field_verification' => [
                'complete' => false,
                'gaps' => [
                    'No field observations establish sidewalk continuity, legal/safe crossings, gate status, accessibility, or current conditions.',
                    'Transport stop/place verification state is reported as stored and is not upgraded by graph proximity.',
                    'OSM geometry may be roadway centerline geometry; crossing tags do not independently establish a safe crossing.',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function endpoints(string $type, array $records): array
    {
        $endpoints = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record['id']) || ! is_numeric($record['id'])) {
                continue;
            }
            $coordinates = $this->coordinates($record);
            $trust = $this->trustStatus($record);
            $endpoint = [
                'type' => $type,
                'id' => (int) $record['id'],
                'coordinates' => $coordinates,
                'coordinate_status' => $coordinates === null ? 'MISSING_OR_INVALID' : 'STORED_COORDINATES',
                'source' => [
                    'provenance_source_id' => isset($record['provenance_source_id']) ? (int) $record['provenance_source_id'] : null,
                    'source_type' => $record['source_type'] ?? null,
                ],
                'trust_status' => $trust,
                'verification_state' => (string) ($record['verification_state'] ?? 'UNKNOWN'),
                'is_active' => (bool) ($record['is_active'] ?? false),
                'is_demo' => (bool) ($record['is_demo'] ?? false),
                'city_id' => isset($record['city_id']) ? (int) $record['city_id'] : null,
            ];
            if ($type === 'stop') {
                $endpoint['is_boarding_point'] = (bool) ($record['is_boarding_point'] ?? false);
                $endpoint['boarding_verification_state'] = (string) ($record['boarding_verification_state'] ?? 'UNKNOWN');
            } else {
                $endpoint['category'] = $record['category'] ?? null;
            }
            $endpoint['snap'] = $coordinates === null
                ? ['status' => 'UNAVAILABLE', 'reason_code' => 'invalid_endpoint']
                : $this->router->snap($coordinates);
            $endpoints[] = $endpoint;
        }
        usort($endpoints, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $endpoints;
    }

    private function endpointSummary(array $endpoints): array
    {
        $summary = [
            'total' => count($endpoints),
            'with_usable_coordinates' => 0,
            'within_snap_policy' => 0,
            'connected_to_largest_component' => 0,
            'snapped_to_smaller_components' => 0,
            'out_of_scope' => 0,
            'blocked_by_mapped_barrier' => 0,
            'unreachable_or_unsnappable' => 0,
            'snap_reason_counts' => [],
            'trust_status_counts' => [],
            'records' => [],
        ];
        foreach ($endpoints as $endpoint) {
            if ($endpoint['coordinates'] !== null) {
                $summary['with_usable_coordinates']++;
            }
            $status = (string) ($endpoint['snap']['status'] ?? 'UNAVAILABLE');
            $reason = (string) ($endpoint['snap']['reason_code'] ?? '');
            $reasonKey = $reason === '' ? 'snapped' : $reason;
            $summary['snap_reason_counts'][$reasonKey] = ($summary['snap_reason_counts'][$reasonKey] ?? 0) + 1;
            if ($status === 'SNAPPED') {
                $summary['within_snap_policy']++;
                if (($endpoint['snap']['largest_component'] ?? false) === true) {
                    $summary['connected_to_largest_component']++;
                } else {
                    $summary['snapped_to_smaller_components']++;
                    $summary['unreachable_or_unsnappable']++;
                }
            } else {
                $summary['unreachable_or_unsnappable']++;
            }
            if ($reason === 'endpoint_out_of_scope') {
                $summary['out_of_scope']++;
            }
            if (($endpoint['snap']['blocked_by_mapped_barrier'] ?? false) === true) {
                $summary['blocked_by_mapped_barrier']++;
            }
            $trust = (string) $endpoint['trust_status'];
            $summary['trust_status_counts'][$trust] = ($summary['trust_status_counts'][$trust] ?? 0) + 1;
            $summary['records'][] = $endpoint;
        }
        ksort($summary['trust_status_counts']);
        ksort($summary['snap_reason_counts']);

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    private function auditTransfers(array $records, array $stopsById): array
    {
        $transfers = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record['id'], $record['from_stop_id'], $record['to_stop_id'])) {
                continue;
            }
            $from = $stopsById[(int) $record['from_stop_id']] ?? null;
            $to = $stopsById[(int) $record['to_stop_id']] ?? null;
            $route = $from !== null && $to !== null && $from['coordinates'] !== null && $to['coordinates'] !== null
                ? $this->router->route($from['coordinates'], $to['coordinates'], 600)
                : ['status' => 'ROUTE_UNAVAILABLE', 'reason_code' => 'invalid_endpoint'];
            $transfers[] = [
                'id' => (int) $record['id'],
                'from_stop_id' => (int) $record['from_stop_id'],
                'to_stop_id' => (int) $record['to_stop_id'],
                'source' => [
                    'provenance_source_id' => isset($record['provenance_source_id']) ? (int) $record['provenance_source_id'] : null,
                    'source_type' => $record['source_type'] ?? null,
                ],
                'trust_status' => $this->trustStatus($record),
                'is_active' => (bool) ($record['is_active'] ?? false),
                'relationship_type' => $record['relationship_type'] ?? null,
                'stored_distance_meters' => isset($record['distance_meters']) ? (float) $record['distance_meters'] : null,
                'graph_route_status' => $route['status'],
                'graph_route_reason_code' => $route['reason_code'],
                'graph_route_distance_meters' => $route['distance_meters'],
            ];
        }
        usort($transfers, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $transfers;
    }

    private function journeyCorpus(array $stops, array $places, array $transfers): array
    {
        $all = [...$stops, ...$places];
        $cases = [];
        $cases[] = $this->pairCase('nearby_stop_to_stop', $this->bestPair($stops, $stops, 20, 500, true), 600);
        $cases[] = $this->pairCase('origin_to_boarding_stop', $this->bestPair($places, $stops, 20, 1_800, true, true), 2_000);
        $cases[] = $this->pairCase('alighting_stop_to_destination', $this->bestPair($stops, $places, 20, 1_800, true, true), 2_000);

        foreach ($transfers as $transfer) {
            $from = $this->findEndpoint($stops, $transfer['from_stop_id']);
            $to = $this->findEndpoint($stops, $transfer['to_stop_id']);
            $cases[] = $this->pairCase('transfer_stop_to_stop', $from !== null && $to !== null ? [$from, $to] : null, 600, [
                'transfer_record_id' => $transfer['id'],
                'transfer_trust_status' => $transfer['trust_status'],
            ]);
        }
        $cases[] = $this->pairCase('same_component_journey', $this->bestPair($all, $all, 20, 500, true), 2_000);
        $cases[] = $this->pairCase('disconnected_component_journey', $this->bestPair($all, $all, 20, 1_500, false), 2_000, [], true);
        $cases[] = $this->pairCase('boundary_area_journey', $this->boundaryPair($all), 2_000);
        $cases[] = $this->pairCase('crossing_tagged_area_journey', $this->crossingPair($all), 600);
        $longEdgeJourney = $this->longEdgeReviewJourney($all);
        $cases[] = $longEdgeJourney['case'];

        $timings = array_values(array_filter(array_column($cases, 'performance_milliseconds'), static fn ($value): bool => is_numeric($value)));
        sort($timings, SORT_NUMERIC);
        $found = count(array_filter($cases, static fn (array $case): bool => ($case['route']['status'] ?? null) === 'ROUTE_FOUND'));
        $evaluated = count(array_filter($cases, static fn (array $case): bool => ($case['status'] ?? null) === 'EVALUATED'));
        $unavailable = $evaluated - $found;

        return [
            'selection' => 'Deterministic pairs chosen from stored stop/place coordinates by component, stored trust state, distance, and ID; no coordinates or boarding claims are fabricated.',
            'case_count' => count($cases),
            'evaluated_case_count' => $evaluated,
            'routes_found' => $found,
            'routes_unavailable' => $unavailable,
            'distance_validation' => 'available routes contain source graph edge lengths and endpoint snap distances separately',
            'geometry_validation' => 'available routes require at least two distinct graph coordinates and actual source-node endpoints',
            'latency_milliseconds' => [
                'p50' => $this->percentile($timings, 0.50),
                'p95' => $this->percentile($timings, 0.95),
                'samples' => count($timings),
            ],
            'long_edge_review_case' => $longEdgeJourney['summary'],
            'cases' => $cases,
        ];
    }

    private function longEdgeReviewJourney(array $endpoints): array
    {
        $candidates = [];
        foreach ($this->router->longEdgeSegments() as $segment) {
            $leftItems = $rightItems = [];
            foreach ($endpoints as $endpoint) {
                if ($endpoint['coordinates'] === null || ($endpoint['snap']['status'] ?? null) !== 'SNAPPED') {
                    continue;
                }
                $position = $this->projectOntoSegment($endpoint['coordinates'], $segment);
                if ($position['distance_meters'] > 500) {
                    continue;
                }
                $candidate = ['endpoint' => $endpoint, ...$position];
                if ($position['fraction'] <= 0.1) {
                    $leftItems[] = $candidate;
                }
                if ($position['fraction'] >= 0.9) {
                    $rightItems[] = $candidate;
                }
            }
            $sortPosition = static fn (array $left, array $right): int => ($left['distance_meters'] <=> $right['distance_meters'])
                ?: ($left['endpoint']['id'] <=> $right['endpoint']['id']);
            usort($leftItems, $sortPosition);
            usort($rightItems, $sortPosition);
            foreach (array_slice($leftItems, 0, 12) as $left) {
                foreach (array_slice($rightItems, 0, 12) as $right) {
                    $origin = $left['endpoint'];
                    $destination = $right['endpoint'];
                    if ($origin['type'] === $destination['type'] && $origin['id'] === $destination['id']) {
                        continue;
                    }
                    if ($origin['snap']['component_id'] !== $destination['snap']['component_id']) {
                        continue;
                    }
                    if ($origin['city_id'] !== null && $destination['city_id'] !== null && $origin['city_id'] !== $destination['city_id']) {
                        continue;
                    }
                    $directDistance = $this->distanceMeters($origin['coordinates'], $destination['coordinates']);
                    if ($directDistance < 20 || $directDistance > 2_000) {
                        continue;
                    }
                    $trustPenalty = ($origin['trust_status'] === 'VERIFIED_RECORD' ? 0 : 1)
                        + ($destination['trust_status'] === 'VERIFIED_RECORD' ? 0 : 1);
                    $candidates[] = [
                        'score' => [
                            $left['distance_meters'] + $right['distance_meters'],
                            $trustPenalty,
                            $directDistance,
                            $segment['way_id'],
                            $segment['segment_index'],
                            $origin['id'],
                            $destination['id'],
                        ],
                        'origin' => $origin,
                        'destination' => $destination,
                        'segment' => $segment,
                    ];
                }
            }
        }
        usort($candidates, static fn (array $left, array $right): int => $left['score'] <=> $right['score']);
        foreach (array_slice($candidates, 0, 48) as $candidate) {
            $route = $this->router->route($candidate['origin']['coordinates'], $candidate['destination']['coordinates'], 2_000);
            $usesReviewedSegment = false;
            if (($route['status'] ?? null) === 'ROUTE_FOUND' && (float) ($route['maximum_edge_meters'] ?? 0) > 250) {
                foreach ($route['edges'] ?? [] as $routeEdge) {
                    if ((int) $routeEdge['way_id'] === (int) $candidate['segment']['way_id']
                        && (int) $routeEdge['segment_index'] === (int) $candidate['segment']['segment_index']) {
                        $usesReviewedSegment = true;

                        break;
                    }
                }
            }
            if (! $usesReviewedSegment) {
                continue;
            }
            $segment = $candidate['segment'];
            $summary = [
                'case_id' => 'long_edge_review_journey',
                'status' => 'ROUTE_USES_REVIEWED_LONG_EDGE',
                'reason_code' => null,
                'osm_way_id' => $segment['way_id'],
                'segment_index' => $segment['segment_index'],
                'source_edge_length_meters' => round((float) $segment['length_meters'], 2),
                'field_review_required' => true,
            ];

            return [
                'summary' => $summary,
                'case' => $this->pairCase('long_edge_review_journey', [$candidate['origin'], $candidate['destination']], 2_000, [
                    'quality_review_edge' => $summary,
                ]),
            ];
        }

        $summary = [
            'case_id' => 'long_edge_review_journey',
            'status' => 'NOT_COVERED_BY_STOP_PLACE_PAIR',
            'reason_code' => 'path_unavailable',
            'field_review_required' => true,
        ];

        return [
            'summary' => $summary,
            'case' => ['case_id' => 'long_edge_review_journey', 'status' => $summary['status'], 'source_records' => null, 'route' => ['status' => 'NOT_EVALUATED', 'reason_code' => $summary['reason_code']]],
        ];
    }

    /** @param array{lat: float, lng: float} $point @param array<string, int|float> $segment @return array{fraction: float, distance_meters: float} */
    private function projectOntoSegment(array $point, array $segment): array
    {
        $scale = 111_320;
        $cosine = cos(deg2rad(((float) $segment['from_latitude'] + (float) $segment['to_latitude']) / 2));
        $startX = (float) $segment['from_longitude'] * $scale * $cosine;
        $startY = (float) $segment['from_latitude'] * $scale;
        $endX = (float) $segment['to_longitude'] * $scale * $cosine;
        $endY = (float) $segment['to_latitude'] * $scale;
        $pointX = (float) $point['lng'] * $scale * $cosine;
        $pointY = (float) $point['lat'] * $scale;
        $deltaX = $endX - $startX;
        $deltaY = $endY - $startY;
        $lengthSquared = $deltaX ** 2 + $deltaY ** 2;
        $fraction = $lengthSquared <= 0 ? 0 : (($pointX - $startX) * $deltaX + ($pointY - $startY) * $deltaY) / $lengthSquared;
        $clampedFraction = min(1, max(0, $fraction));

        return [
            'fraction' => $fraction,
            'distance_meters' => hypot($pointX - ($startX + $clampedFraction * $deltaX), $pointY - ($startY + $clampedFraction * $deltaY)),
        ];
    }

    private function pairCase(string $caseId, ?array $pair, float $maximumDistanceMeters, array $extra = [], bool $requireDifferentComponents = false): array
    {
        if ($pair === null) {
            return ['case_id' => $caseId, 'status' => 'NO_ELIGIBLE_SOURCE_PAIR', 'source_records' => null, 'route' => ['status' => 'ROUTE_UNAVAILABLE', 'reason_code' => 'snap_unavailable'], ...$extra];
        }
        [$origin, $destination] = $pair;
        $startedAt = hrtime(true);
        $route = $origin['coordinates'] !== null && $destination['coordinates'] !== null
            ? $this->router->route($origin['coordinates'], $destination['coordinates'], $maximumDistanceMeters)
            : ['status' => 'ROUTE_UNAVAILABLE', 'reason_code' => 'invalid_endpoint'];
        $elapsed = round((hrtime(true) - $startedAt) / 1_000_000, 3);
        $expectedDisconnected = $requireDifferentComponents
            && ($origin['snap']['component_id'] ?? null) !== ($destination['snap']['component_id'] ?? null);
        $geometryValid = ($route['status'] ?? null) !== 'ROUTE_FOUND'
            ? null
            : $this->validRouteGeometry($route, $origin, $destination);

        return [
            'case_id' => $caseId,
            'status' => 'EVALUATED',
            'maximum_distance_meters' => $maximumDistanceMeters,
            'source_records' => [
                'origin' => $this->publicEndpoint($origin),
                'destination' => $this->publicEndpoint($destination),
            ],
            'expected_disconnected_components' => $expectedDisconnected,
            'performance_milliseconds' => $elapsed,
            'geometry_valid' => $geometryValid,
            'route' => $route,
            ...$extra,
        ];
    }

    private function bestPair(array $leftItems, array $rightItems, float $minimumDistance, float $maximumDistance, bool $sameComponent, bool $requireSameCity = false, bool $preferLongest = false): ?array
    {
        $best = null;
        $bestScore = null;
        $sameCollection = $leftItems === $rightItems;
        $largestComponentId = $sameComponent && ! $preferLongest ? $this->router->componentSummary()['largest_component_id'] : null;
        foreach ($leftItems as $leftIndex => $left) {
            if ($left['coordinates'] === null || ($left['snap']['status'] ?? null) !== 'SNAPPED') {
                continue;
            }
            foreach ($rightItems as $rightIndex => $right) {
                if ($left['type'] === $right['type'] && $left['id'] === $right['id']) {
                    continue;
                }
                if ($sameCollection && $rightIndex <= $leftIndex) {
                    continue;
                }
                if ($right['coordinates'] === null || ($right['snap']['status'] ?? null) !== 'SNAPPED') {
                    continue;
                }
                if ($requireSameCity && $left['city_id'] !== null && $right['city_id'] !== null && $left['city_id'] !== $right['city_id']) {
                    continue;
                }
                $leftComponent = $left['snap']['component_id'];
                $rightComponent = $right['snap']['component_id'];
                if ($sameComponent !== ($leftComponent === $rightComponent)) {
                    continue;
                }
                if ($sameComponent && ! $preferLongest && $leftComponent !== $largestComponentId) {
                    continue;
                }
                $distance = $this->distanceMeters($left['coordinates'], $right['coordinates']);
                if ($distance < $minimumDistance || $distance > $maximumDistance) {
                    continue;
                }
                $trustPenalty = ($left['trust_status'] === 'VERIFIED_RECORD' ? 0 : 1) + ($right['trust_status'] === 'VERIFIED_RECORD' ? 0 : 1);
                $score = $preferLongest
                    ? [-$distance, $trustPenalty, $left['id'], $right['id']]
                    : [$trustPenalty, $distance, $left['id'], $right['id']];
                if ($bestScore === null || ($score <=> $bestScore) < 0) {
                    $best = [$left, $right];
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    private function boundaryPair(array $endpoints): ?array
    {
        $bounds = $this->router->metadata()['source_bounds'] ?? [];
        if (! isset($bounds['min_latitude'], $bounds['max_latitude'], $bounds['min_longitude'], $bounds['max_longitude'])) {
            return null;
        }
        $ranked = array_values(array_filter($endpoints, static fn (array $endpoint): bool => $endpoint['coordinates'] !== null && ($endpoint['snap']['status'] ?? null) === 'SNAPPED'));
        usort($ranked, function (array $left, array $right) use ($bounds): int {
            $leftMargin = $this->boundaryMarginMeters($left['coordinates'], $bounds);
            $rightMargin = $this->boundaryMarginMeters($right['coordinates'], $bounds);

            return ($leftMargin <=> $rightMargin) ?: ($left['id'] <=> $right['id']);
        });
        foreach ($ranked as $origin) {
            $partners = array_values(array_filter($ranked, static fn (array $candidate): bool => $candidate['id'] !== $origin['id']
                && $candidate['type'] !== $origin['type']
                && $candidate['snap']['component_id'] === $origin['snap']['component_id']));
            usort($partners, fn (array $left, array $right): int => ($this->distanceMeters($origin['coordinates'], $left['coordinates']) <=> $this->distanceMeters($origin['coordinates'], $right['coordinates'])) ?: ($left['id'] <=> $right['id']));
            foreach ($partners as $partner) {
                $distance = $this->distanceMeters($origin['coordinates'], $partner['coordinates']);
                if ($distance >= 20 && $distance <= 2_000) {
                    return [$origin, $partner];
                }
            }
        }

        return null;
    }

    private function crossingPair(array $endpoints): ?array
    {
        $crossingEndpoints = array_values(array_filter($endpoints, static fn (array $endpoint): bool => $endpoint['coordinates'] !== null
            && ($endpoint['snap']['status'] ?? null) === 'SNAPPED'
            && ($endpoint['snap']['crossing'] ?? null) !== null));
        usort($crossingEndpoints, static fn (array $left, array $right): int => ($left['snap']['snap_distance_meters'] <=> $right['snap']['snap_distance_meters']) ?: ($left['id'] <=> $right['id']));
        foreach ($crossingEndpoints as $origin) {
            foreach ($endpoints as $destination) {
                if ($origin['id'] === $destination['id'] || $destination['coordinates'] === null
                    || ($destination['snap']['status'] ?? null) !== 'SNAPPED'
                    || $origin['snap']['component_id'] !== $destination['snap']['component_id']) {
                    continue;
                }
                $distance = $this->distanceMeters($origin['coordinates'], $destination['coordinates']);
                if ($distance >= 20 && $distance <= 600) {
                    return [$origin, $destination];
                }
            }
        }

        return null;
    }

    private function publicEndpoint(array $endpoint): array
    {
        return [
            'type' => $endpoint['type'],
            'id' => $endpoint['id'],
            'coordinates' => $endpoint['coordinates'],
            'source' => $endpoint['source'],
            'trust_status' => $endpoint['trust_status'],
            'verification_state' => $endpoint['verification_state'],
            'snap' => $endpoint['snap'],
        ];
    }

    private function findEndpoint(array $endpoints, int $id): ?array
    {
        foreach ($endpoints as $endpoint) {
            if ($endpoint['id'] === $id) {
                return $endpoint;
            }
        }

        return null;
    }

    private function coordinates(array $record): ?array
    {
        if (! isset($record['lat'], $record['lng']) || ! is_numeric($record['lat']) || ! is_numeric($record['lng'])) {
            return null;
        }
        $latitude = (float) $record['lat'];
        $longitude = (float) $record['lng'];
        if (! is_finite($latitude) || ! is_finite($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['lat' => $latitude, 'lng' => $longitude];
    }

    private function trustStatus(array $record): string
    {
        if ((bool) ($record['is_demo'] ?? false)) {
            return 'DRAFT_DEMO';
        }
        if (strtoupper((string) ($record['verification_state'] ?? '')) === 'VERIFIED') {
            return 'VERIFIED_RECORD';
        }
        if (isset($record['source_type']) || isset($record['provenance_source_id'])) {
            return 'UNVERIFIED_RECORD';
        }

        return 'UNKNOWN_SOURCE';
    }

    private function validRouteGeometry(array $route, array $origin, array $destination): bool
    {
        $coordinates = $route['geometry']['coordinates'] ?? null;
        if (($route['geometry']['type'] ?? null) !== 'LineString' || ! is_array($coordinates) || count($coordinates) < 2) {
            return false;
        }
        foreach ($coordinates as $coordinate) {
            if (! is_array($coordinate) || count($coordinate) !== 2 || ! is_finite((float) $coordinate[0]) || ! is_finite((float) $coordinate[1])) {
                return false;
            }
        }
        $originSnap = $origin['snap'];
        $destinationSnap = $destination['snap'];
        $first = $coordinates[0];
        $last = $coordinates[count($coordinates) - 1];

        return abs((float) $first[0] - (float) $originSnap['node_longitude']) <= 0.0000001
            && abs((float) $first[1] - (float) $originSnap['node_latitude']) <= 0.0000001
            && abs((float) $last[0] - (float) $destinationSnap['node_longitude']) <= 0.0000001
            && abs((float) $last[1] - (float) $destinationSnap['node_latitude']) <= 0.0000001;
    }

    private function boundaryMarginMeters(array $point, array $bounds): float
    {
        $latitudeMargin = min((float) $point['lat'] - (float) $bounds['min_latitude'], (float) $bounds['max_latitude'] - (float) $point['lat']) * 111_320;
        $longitudeMargin = min((float) $point['lng'] - (float) $bounds['min_longitude'], (float) $bounds['max_longitude'] - (float) $point['lng'])
            * 111_320 * cos(deg2rad((float) $point['lat']));

        return min($latitudeMargin, $longitudeMargin);
    }

    private function distanceMeters(array $left, array $right): float
    {
        $earthRadius = 6_371_000;
        $latitudeDelta = deg2rad($right['lat'] - $left['lat']);
        $longitudeDelta = deg2rad($right['lng'] - $left['lng']);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($left['lat'])) * cos(deg2rad($right['lat'])) * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    private function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        $index = max(0, min(count($values) - 1, (int) ceil($percentile * count($values)) - 1));

        return round((float) $values[$index], 3);
    }
}
