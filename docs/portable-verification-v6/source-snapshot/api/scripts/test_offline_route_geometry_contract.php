<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Services/Routing/OfflinePedestrianRouteGeometryContract.php';

use App\Services\Routing\OfflinePedestrianRouteGeometryContract;

$source = str_repeat('a', 64);
$fingerprint = str_repeat('b', 64);
$route = [
    'status' => 'ROUTE_FOUND',
    'source' => 'OPENSTREETMAP',
    'source_sha256' => $source,
    'graph_fingerprint' => $fingerprint,
    'field_safety_verified' => false,
    'geometry' => ['type' => 'LineString', 'coordinates' => [[123.0, 10.0], [123.001, 10.0]]],
    'node_ids' => [100, 101],
    'edges' => [[
        'way_id' => 500,
        'segment_index' => 0,
        'from_node_id' => 100,
        'to_node_id' => 101,
        'length_meters' => 100.0,
    ]],
    'origin_snap' => [
        'status' => 'SNAPPED', 'node_id' => 100, 'node_longitude' => 123.0,
        'node_latitude' => 10.0, 'snap_distance_meters' => 2.0,
    ],
    'destination_snap' => [
        'status' => 'SNAPPED', 'node_id' => 101, 'node_longitude' => 123.001,
        'node_latitude' => 10.0, 'snap_distance_meters' => 3.0,
    ],
    'distance_meters' => 100.0,
    'total_distance_with_approximate_snaps_meters' => 105.0,
    'walking_speed_meters_per_second' => 1.2,
    'estimated_duration_seconds' => 88,
    'duration_label' => 'estimate_only',
];

$passed = 0;
$expectAccept = static function (array $candidate) use (&$passed, $source, $fingerprint): void {
    OfflinePedestrianRouteGeometryContract::assertResult($candidate, $source, $fingerprint);
    $passed++;
};
$expectReject = static function (array $candidate) use (&$passed, $source, $fingerprint): void {
    try {
        OfflinePedestrianRouteGeometryContract::assertResult($candidate, $source, $fingerprint);
    } catch (\InvalidArgumentException) {
        $passed++;

        return;
    }

    throw new RuntimeException('Expected malformed route evidence to be rejected.');
};

$expectAccept($route);

$reversed = $route;
$reversed['edges'][0]['from_node_id'] = 101;
$reversed['edges'][0]['to_node_id'] = 100;
$expectReject($reversed);

$connector = $route;
$connector['geometry']['coordinates'][0] = [123.00001, 10.0];
$expectReject($connector);

$badSource = $route;
$badSource['source_sha256'] = str_repeat('0', 64);
$expectReject($badSource);

$badDistance = $route;
$badDistance['total_distance_with_approximate_snaps_meters'] = 104.0;
$expectReject($badDistance);

$disconnected = [
    'status' => 'ROUTE_UNAVAILABLE',
    'reason_code' => 'component_disconnected',
    'geometry' => null,
    'distance_meters' => null,
    'node_ids' => [],
    'edges' => [],
    'origin_component' => 1,
    'destination_component' => 2,
];
$expectAccept($disconnected);

$fabricatedFailurePath = $disconnected;
$fabricatedFailurePath['geometry'] = ['type' => 'LineString', 'coordinates' => [[123.0, 10.0], [123.001, 10.0]]];
$expectReject($fabricatedFailurePath);

printf("GEOMETRY_CONTRACT_SMOKE=PASS\nCASES=%d\nGRAPH_READ=NO\nLARAVEL_BOOTSTRAP=NO\n", $passed);
