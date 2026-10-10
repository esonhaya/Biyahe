<?php

namespace App\Services\Routing;

use InvalidArgumentException;

/** Reusable source-identity, path-continuity, and distance assertions for offline routes. */
final class OfflinePedestrianRouteGeometryContract
{
    /** @param array<string, mixed> $route */
    public static function assertResult(
        array $route,
        string $sourceSha256,
        string $graphFingerprint,
        float $maximumSnapDistanceMeters = 25.0,
        int $maximumGeometryPoints = 2_048,
    ): void {
        if (($route['status'] ?? null) === 'ROUTE_UNAVAILABLE') {
            self::assertUnavailable($route);

            return;
        }
        if (($route['status'] ?? null) !== 'ROUTE_FOUND') {
            throw new InvalidArgumentException('Offline route status is missing or unsupported.');
        }
        if (($route['source'] ?? null) !== 'OPENSTREETMAP'
            || ! hash_equals(strtolower($sourceSha256), strtolower((string) ($route['source_sha256'] ?? '')))
            || ! hash_equals(strtolower($graphFingerprint), strtolower((string) ($route['graph_fingerprint'] ?? '')))
            || ($route['field_safety_verified'] ?? null) !== false) {
            throw new InvalidArgumentException('Offline route source identity or field-safety boundary is invalid.');
        }
        if (! is_finite($maximumSnapDistanceMeters) || $maximumSnapDistanceMeters <= 0
            || $maximumSnapDistanceMeters > 25.0 || $maximumGeometryPoints < 2 || $maximumGeometryPoints > 2_048) {
            throw new InvalidArgumentException('Offline route geometry policy exceeds its supported bounds.');
        }

        $geometry = $route['geometry'] ?? null;
        $coordinates = is_array($geometry) && ($geometry['type'] ?? null) === 'LineString'
            ? ($geometry['coordinates'] ?? null)
            : null;
        $nodeIds = $route['node_ids'] ?? null;
        $edges = $route['edges'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2 || count($coordinates) > $maximumGeometryPoints
            || ! is_array($nodeIds) || count($nodeIds) !== count($coordinates)
            || ! is_array($edges) || count($edges) !== count($coordinates) - 1) {
            throw new InvalidArgumentException('Successful offline route requires aligned coordinates, node IDs, and directed edges.');
        }

        $seenNodes = [];
        $previousCoordinate = null;
        foreach ($coordinates as $index => $coordinate) {
            if (! is_array($coordinate) || count($coordinate) !== 2
                || ! self::finiteNumber($coordinate[0]) || ! self::finiteNumber($coordinate[1])
                || (float) $coordinate[0] < -180 || (float) $coordinate[0] > 180
                || (float) $coordinate[1] < -90 || (float) $coordinate[1] > 90
                || ! is_int($nodeIds[$index] ?? null) || $nodeIds[$index] <= 0
                || isset($seenNodes[$nodeIds[$index]])) {
                throw new InvalidArgumentException('Offline route contains invalid or repeated graph geometry identity.');
            }
            $seenNodes[$nodeIds[$index]] = true;
            if ($previousCoordinate !== null && abs((float) $coordinate[0] - $previousCoordinate[0]) < 1.0e-12
                && abs((float) $coordinate[1] - $previousCoordinate[1]) < 1.0e-12) {
                throw new InvalidArgumentException('Offline route contains duplicate adjacent coordinates.');
            }
            $previousCoordinate = [(float) $coordinate[0], (float) $coordinate[1]];
        }

        $origin = $route['origin_snap'] ?? null;
        $destination = $route['destination_snap'] ?? null;
        self::assertSnap($origin, $maximumSnapDistanceMeters, 'origin');
        self::assertSnap($destination, $maximumSnapDistanceMeters, 'destination');
        if ($nodeIds[0] !== $origin['node_id'] || $nodeIds[count($nodeIds) - 1] !== $destination['node_id']
            || ! self::coordinateMatches($coordinates[0], $origin['node_longitude'], $origin['node_latitude'])
            || ! self::coordinateMatches($coordinates[count($coordinates) - 1], $destination['node_longitude'], $destination['node_latitude'])) {
            throw new InvalidArgumentException('Offline graph geometry must begin and end at the verified snapped graph nodes.');
        }

        $edgeDistance = 0.0;
        foreach ($edges as $index => $edge) {
            if (! is_array($edge) || ! is_int($edge['way_id'] ?? null) || $edge['way_id'] <= 0
                || ! is_int($edge['segment_index'] ?? null) || $edge['segment_index'] < 0
                || ! is_int($edge['from_node_id'] ?? null) || ! is_int($edge['to_node_id'] ?? null)
                || $edge['from_node_id'] !== $nodeIds[$index] || $edge['to_node_id'] !== $nodeIds[$index + 1]
                || ! self::finiteNumber($edge['length_meters'] ?? null) || (float) $edge['length_meters'] <= 0) {
                throw new InvalidArgumentException('Offline route edge direction or identity does not join the ordered graph nodes.');
            }
            $edgeDistance += (float) $edge['length_meters'];
        }

        $networkDistance = $route['distance_meters'] ?? null;
        $totalDistance = $route['total_distance_with_approximate_snaps_meters'] ?? null;
        $speed = $route['walking_speed_meters_per_second'] ?? null;
        $duration = $route['estimated_duration_seconds'] ?? null;
        if (! self::finiteNumber($networkDistance) || (float) $networkDistance <= 0
            || ! self::finiteNumber($totalDistance) || (float) $totalDistance <= 0
            || ! self::finiteNumber($speed) || (float) $speed <= 0
            || ! is_int($duration) || $duration < 0 || ($route['duration_label'] ?? null) !== 'estimate_only'
            || abs($edgeDistance - (float) $networkDistance) > 0.05
            || abs((float) $totalDistance - ((float) $networkDistance + (float) $origin['snap_distance_meters'] + (float) $destination['snap_distance_meters'])) > 0.05
            || abs($duration - (int) round((float) $totalDistance / (float) $speed)) > 1) {
            throw new InvalidArgumentException('Offline route distance or estimated-duration evidence is inconsistent.');
        }
    }

    /** @param array<string, mixed> $route */
    private static function assertUnavailable(array $route): void
    {
        if (! is_string($route['reason_code'] ?? null) || $route['reason_code'] === ''
            || ($route['geometry'] ?? null) !== null
            || ($route['distance_meters'] ?? null) !== null
            || (isset($route['node_ids']) && $route['node_ids'] !== [])
            || (isset($route['edges']) && $route['edges'] !== [])) {
            throw new InvalidArgumentException('Unavailable offline routes must have a reason and no path geometry or distance.');
        }
        if (($route['reason_code'] ?? null) === 'component_disconnected'
            && (! isset($route['origin_component'], $route['destination_component'])
                || $route['origin_component'] === $route['destination_component'])) {
            throw new InvalidArgumentException('Disconnected route evidence must identify two different components.');
        }
    }

    /** @param mixed $snap */
    private static function assertSnap(mixed $snap, float $maximumDistance, string $label): void
    {
        if (! is_array($snap) || ($snap['status'] ?? null) !== 'SNAPPED'
            || ! is_int($snap['node_id'] ?? null) || $snap['node_id'] <= 0
            || ! self::finiteNumber($snap['snap_distance_meters'] ?? null)
            || (float) $snap['snap_distance_meters'] < 0 || (float) $snap['snap_distance_meters'] > $maximumDistance
            || ! self::finiteNumber($snap['node_longitude'] ?? null) || ! self::finiteNumber($snap['node_latitude'] ?? null)
            || (float) $snap['node_longitude'] < -180 || (float) $snap['node_longitude'] > 180
            || (float) $snap['node_latitude'] < -90 || (float) $snap['node_latitude'] > 90) {
            throw new InvalidArgumentException("Offline route {$label} snap is invalid or exceeds the 25 m policy.");
        }
    }

    private static function finiteNumber(mixed $value): bool
    {
        return ! is_bool($value) && (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function coordinateMatches(array $coordinate, float|int $longitude, float|int $latitude): bool
    {
        return abs((float) $coordinate[0] - (float) $longitude) <= 1.0e-9
            && abs((float) $coordinate[1] - (float) $latitude) <= 1.0e-9;
    }
}
