<?php

namespace App\Services\Routing;

use PDO;
use PDOException;
use SplPriorityQueue;
use Throwable;

/** Routes against an immutable, already validated SQLite graph without PostgreSQL. */
final class OfflinePedestrianGraphRouter
{
    private ?PDO $sqlite = null;

    private array $metadata = [];

    private ?string $blockedReason = null;

    /** @var array<int, int> OSM node ID to deterministic minimum-node component ID */
    private array $componentByNode = [];

    /** @var array<int, int> deterministic component ID to node count */
    private array $componentSizes = [];

    private ?int $largestComponentId = null;

    private bool $initialized = false;

    public function __construct(
        private readonly string $graphPath,
        private readonly string $expectedSourceSha256,
        private readonly array $validation,
        private readonly float $maximumSnapDistanceMeters = 25.0,
        private readonly int $maximumEdgesPerRoute = 50_000,
        private readonly int $maximumVisitedNodesPerRoute = 20_000,
        private readonly float $walkingSpeedMetersPerSecond = 1.2,
    ) {
        if (! is_file($graphPath) || ! is_readable($graphPath)) {
            $this->blockedReason = 'graph_unavailable';

            return;
        }
        if (($validation['artifact_ready'] ?? false) !== true) {
            $this->blockedReason = 'graph_unavailable';

            return;
        }
        if (! preg_match('/^[a-f0-9]{64}$/i', $expectedSourceSha256)
            || ! hash_equals(strtolower($expectedSourceSha256), strtolower((string) ($validation['source_sha256'] ?? '')))) {
            $this->blockedReason = 'source_mismatch';

            return;
        }
        if (($validation['routing_ready'] ?? false) !== true
            || ($validation['routing_capabilities']['complete_restriction_inventory'] ?? false) !== true
            || ($validation['routing_capabilities']['routing_ready'] ?? false) !== true) {
            $this->blockedReason = 'unsupported_routing_capability';

            return;
        }

        try {
            $uri = 'sqlite:file:'.$graphPath.'?mode=ro';
            $this->sqlite = new PDO($uri, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec('PRAGMA temp_store=FILE');
            $this->sqlite->exec('PRAGMA cache_size=-8192');
            foreach ($this->sqlite->query('SELECT key, value FROM metadata') as $row) {
                $this->metadata[(string) $row['key']] = json_decode((string) $row['value'], true, 32, JSON_THROW_ON_ERROR);
            }
            if (! hash_equals(strtolower($expectedSourceSha256), strtolower((string) ($this->metadata['source_sha256'] ?? '')))) {
                $this->blockedReason = 'source_mismatch';
                $this->sqlite = null;

                return;
            }
            if (($this->metadata['state'] ?? null) !== 'COMPLETE'
                || ($this->metadata['input_complete'] ?? false) !== true
                || ! is_array($this->metadata['source_bounds'] ?? null)) {
                $this->blockedReason = 'graph_unavailable';
                $this->sqlite = null;
            }
        } catch (Throwable) {
            $this->blockedReason = 'graph_unavailable';
            $this->sqlite = null;
        }
    }

    /** @param array{lat: float|int, lng: float|int} $point @return array<string, mixed> */
    public function snap(array $point): array
    {
        if ($this->blockedReason !== null) {
            return $this->unavailable($this->blockedReason);
        }
        if (! $this->validPoint($point)) {
            return $this->unavailable('invalid_endpoint');
        }
        if (! $this->insideScope($point)) {
            return $this->unavailable('endpoint_out_of_scope');
        }
        if (! $this->initialize()) {
            return $this->unavailable('resource_budget_exceeded');
        }

        $latitudeDelta = $this->maximumSnapDistanceMeters / 111_320;
        $longitudeDelta = $this->maximumSnapDistanceMeters / max(1, 111_320 * cos(deg2rad((float) $point['lat'])));
        $minimumLatitude = (float) $point['lat'] - $latitudeDelta;
        $maximumLatitude = (float) $point['lat'] + $latitudeDelta;
        $minimumLongitude = (float) $point['lng'] - $longitudeDelta;
        $maximumLongitude = (float) $point['lng'] + $longitudeDelta;
        $candidateStatement = $this->sqlite->prepare(
            'SELECT nodes.osm_node_id, nodes.latitude, nodes.longitude, nodes.walkable, nodes.barrier, nodes.crossing '
            .'FROM temp.route_nodes_rtree AS indexed '
            .'JOIN main.graph_nodes AS nodes ON nodes.osm_node_id=indexed.id '
            .'WHERE indexed.min_longitude<=? AND indexed.max_longitude>=? '
            .'AND indexed.min_latitude<=? AND indexed.max_latitude>=? '
            .'ORDER BY nodes.walkable DESC, nodes.osm_node_id'
        );
        $candidateStatement->execute([$maximumLongitude, $minimumLongitude, $maximumLatitude, $minimumLatitude]);

        $nearestWalkable = null;
        $nearestBlocked = null;
        while ($row = $candidateStatement->fetch(PDO::FETCH_ASSOC)) {
            $distance = $this->distanceMeters((float) $point['lat'], (float) $point['lng'], (float) $row['latitude'], (float) $row['longitude']);
            if ($distance > $this->maximumSnapDistanceMeters) {
                continue;
            }
            $candidate = [
                'node_id' => (int) $row['osm_node_id'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'distance_meters' => $distance,
                'barrier' => $row['barrier'] === null ? null : (string) $row['barrier'],
                'crossing' => $row['crossing'] === null ? null : (string) $row['crossing'],
            ];
            if ((int) $row['walkable'] === 1) {
                if ($nearestWalkable === null || $this->candidateBefore($candidate, $nearestWalkable)) {
                    $nearestWalkable = $candidate;
                }
            } elseif ($nearestBlocked === null || $this->candidateBefore($candidate, $nearestBlocked)) {
                $nearestBlocked = $candidate;
            }
        }
        $candidateStatement->closeCursor();

        if ($nearestWalkable === null) {
            return $this->unavailable('snap_unavailable', [
                'nearest_blocked_node' => $nearestBlocked,
                'maximum_snap_distance_meters' => $this->maximumSnapDistanceMeters,
            ]);
        }
        if ($nearestWalkable['distance_meters'] > 1.0 && $this->connectorCrossesBarrier($point, $nearestWalkable)) {
            return $this->unavailable('snap_unavailable', [
                'blocked_by_mapped_barrier' => true,
                'nearest_walkable_node' => $nearestWalkable['node_id'],
                'snap_distance_meters' => round($nearestWalkable['distance_meters'], 2),
            ]);
        }

        $nodeId = $nearestWalkable['node_id'];
        $componentId = $this->componentByNode[$nodeId] ?? null;
        if ($componentId === null) {
            return $this->unavailable('graph_unavailable');
        }

        return [
            'status' => 'SNAPPED',
            'reason_code' => null,
            'node_id' => $nodeId,
            'node_latitude' => $nearestWalkable['latitude'],
            'node_longitude' => $nearestWalkable['longitude'],
            'snap_distance_meters' => round($nearestWalkable['distance_meters'], 3),
            'component_id' => $componentId,
            'component_size' => $this->componentSizes[$componentId] ?? 0,
            'largest_component' => $componentId === $this->largestComponentId,
            'barrier' => $nearestWalkable['barrier'],
            'crossing' => $nearestWalkable['crossing'],
            'connector_is_approximate' => $nearestWalkable['distance_meters'] > 1.0,
            'field_access_verified' => false,
        ];
    }

    /** @param array{lat: float|int, lng: float|int} $origin @param array{lat: float|int, lng: float|int} $destination @return array<string, mixed> */
    public function route(array $origin, array $destination, float $maximumDistanceMeters = 2_000.0): array
    {
        if ($this->blockedReason !== null) {
            return $this->unavailable($this->blockedReason);
        }
        if (! $this->validPoint($origin) || ! $this->validPoint($destination)
            || ! is_finite($maximumDistanceMeters) || $maximumDistanceMeters <= 0 || $maximumDistanceMeters > 10_000) {
            return $this->unavailable('invalid_endpoint');
        }
        if ($this->distanceMeters((float) $origin['lat'], (float) $origin['lng'], (float) $destination['lat'], (float) $destination['lng']) < 0.01) {
            return $this->unavailable('invalid_endpoint', ['detail' => 'identical_endpoints_have_no_walking_leg']);
        }

        $start = $this->snap($origin);
        if (($start['status'] ?? null) !== 'SNAPPED') {
            return $this->unavailable((string) ($start['reason_code'] ?? 'snap_unavailable'), ['endpoint' => 'origin', 'snap' => $start]);
        }
        $end = $this->snap($destination);
        if (($end['status'] ?? null) !== 'SNAPPED') {
            return $this->unavailable((string) ($end['reason_code'] ?? 'snap_unavailable'), ['endpoint' => 'destination', 'snap' => $end]);
        }
        if ($start['component_id'] !== $end['component_id']) {
            return $this->unavailable('component_disconnected', [
                'origin_component' => $start['component_id'],
                'destination_component' => $end['component_id'],
                'origin_snap_distance_meters' => $start['snap_distance_meters'],
                'destination_snap_distance_meters' => $end['snap_distance_meters'],
            ]);
        }
        if ((int) $start['node_id'] === (int) $end['node_id']) {
            return $this->unavailable('path_unavailable', ['detail' => 'both_endpoints_snap_to_one_node']);
        }
        if (! $this->initialize()) {
            return $this->unavailable('resource_budget_exceeded');
        }

        $edges = $this->routeEdges($origin, $destination, $maximumDistanceMeters);
        if ($edges === null) {
            return $this->unavailable('resource_budget_exceeded', ['edge_limit' => $this->maximumEdgesPerRoute]);
        }
        [$adjacency, $coordinates] = $this->adjacency($edges);
        unset($edges);
        $search = $this->shortestPath((int) $start['node_id'], (int) $end['node_id'], $adjacency, $maximumDistanceMeters);
        unset($adjacency);
        if ($search['limited']) {
            return $this->unavailable('resource_budget_exceeded', ['visited_node_limit' => $this->maximumVisitedNodesPerRoute]);
        }
        if ($search['path'] === []) {
            return $this->unavailable('path_unavailable', ['distance_bound_meters' => $maximumDistanceMeters, 'distance_bound_reached' => $search['distance_bound_reached']]);
        }

        $pathCoordinates = [];
        foreach ($search['path'] as $nodeId) {
            $coordinate = $coordinates[$nodeId] ?? null;
            if ($coordinate === null || ! is_finite($coordinate[0]) || ! is_finite($coordinate[1])) {
                return $this->unavailable('graph_unavailable', ['detail' => 'route_geometry_endpoint_missing']);
            }
            $pathCoordinates[] = [$coordinate[0], $coordinate[1]];
        }
        if (count($pathCoordinates) < 2 || $this->hasDuplicateGeometryPoints($pathCoordinates)) {
            return $this->unavailable('path_unavailable', ['detail' => 'route_geometry_is_degenerate']);
        }

        $totalMeters = $search['network_distance'] + (float) $start['snap_distance_meters'] + (float) $end['snap_distance_meters'];
        $speed = is_finite($this->walkingSpeedMetersPerSecond) && $this->walkingSpeedMetersPerSecond > 0
            ? $this->walkingSpeedMetersPerSecond
            : 1.2;

        return [
            'status' => 'ROUTE_FOUND',
            'reason_code' => null,
            'distance_meters' => round($search['network_distance'], 2),
            'total_distance_with_approximate_snaps_meters' => round($totalMeters, 2),
            'estimated_duration_seconds' => (int) round($totalMeters / $speed),
            'duration_label' => 'estimate_only',
            'walking_speed_meters_per_second' => $speed,
            'geometry' => ['type' => 'LineString', 'coordinates' => $pathCoordinates],
            'node_ids' => $search['path'],
            'edges' => $search['edges'],
            'maximum_edge_meters' => round($search['maximum_edge_meters'], 2),
            'origin_snap' => $start,
            'destination_snap' => $end,
            'component_id' => $start['component_id'],
            'source' => 'OPENSTREETMAP',
            'source_sha256' => $this->expectedSourceSha256,
            'graph_fingerprint' => $this->metadata['graph_content_sha256'] ?? null,
            'field_safety_verified' => false,
            'warnings' => [
                'Geometry follows source OSM pedestrian-accessible ways; it does not verify sidewalks, crossings, barriers missing from OSM, or current field conditions.',
                'Endpoint connectors are approximate straight-line snaps and are not included in the displayed graph geometry.',
                'Walking duration is an estimate using a fixed speed assumption, not a precise ETA.',
            ],
        ];
    }

    /** @return array{components: int, largest_component_id: ?int, largest_component_nodes: int, component_sizes: array<int, int>} */
    public function componentSummary(): array
    {
        if ($this->blockedReason !== null || ! $this->initialize()) {
            return ['components' => 0, 'largest_component_id' => null, 'largest_component_nodes' => 0, 'component_sizes' => []];
        }

        return [
            'components' => count($this->componentSizes),
            'largest_component_id' => $this->largestComponentId,
            'largest_component_nodes' => $this->largestComponentId === null ? 0 : $this->componentSizes[$this->largestComponentId],
            'component_sizes' => $this->componentSizes,
        ];
    }

    /** @return list<array<string, int|float>> Source graph edge identity and endpoint coordinates. */
    public function longEdgeSegments(float $minimumLengthMeters = 250.0): array
    {
        if ($this->blockedReason !== null || ! is_finite($minimumLengthMeters) || $minimumLengthMeters <= 0 || ! $this->initialize()) {
            return [];
        }
        $statement = $this->sqlite->prepare(
            'SELECT way_id, segment_index, from_node_id, to_node_id, length_meters, from_latitude, from_longitude, to_latitude, to_longitude '
            .'FROM temp.route_edge_map WHERE length_meters>? ORDER BY length_meters DESC, way_id, segment_index'
        );
        $statement->execute([$minimumLengthMeters]);
        $segments = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $segments[] = [
                'way_id' => (int) $row['way_id'],
                'segment_index' => (int) $row['segment_index'],
                'from_node_id' => (int) $row['from_node_id'],
                'to_node_id' => (int) $row['to_node_id'],
                'length_meters' => (float) $row['length_meters'],
                'from_latitude' => (float) $row['from_latitude'],
                'from_longitude' => (float) $row['from_longitude'],
                'to_latitude' => (float) $row['to_latitude'],
                'to_longitude' => (float) $row['to_longitude'],
            ];
        }
        $statement->closeCursor();

        return $segments;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    private function initialize(): bool
    {
        if ($this->initialized) {
            return true;
        }
        if ($this->sqlite === null) {
            return false;
        }

        try {
            $this->sqlite->exec('CREATE VIRTUAL TABLE temp.route_nodes_rtree USING rtree(id, min_longitude, max_longitude, min_latitude, max_latitude)');
            $this->sqlite->exec('INSERT INTO temp.route_nodes_rtree SELECT osm_node_id, longitude, longitude, latitude, latitude FROM main.graph_nodes');
            $this->sqlite->exec('CREATE TEMP TABLE route_edge_map (id INTEGER PRIMARY KEY, way_id INTEGER NOT NULL, segment_index INTEGER NOT NULL, from_node_id INTEGER NOT NULL, to_node_id INTEGER NOT NULL, bidirectional INTEGER NOT NULL, length_meters REAL NOT NULL, from_latitude REAL NOT NULL, from_longitude REAL NOT NULL, to_latitude REAL NOT NULL, to_longitude REAL NOT NULL)');
            $this->sqlite->exec(
                'INSERT INTO temp.route_edge_map (way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, from_latitude, from_longitude, to_latitude, to_longitude) '
                .'SELECT edges.osm_way_id, edges.segment_index, edges.from_node_id, edges.to_node_id, edges.bidirectional, edges.length_meters, '
                .'from_nodes.latitude, from_nodes.longitude, to_nodes.latitude, to_nodes.longitude '
                .'FROM main.graph_edges AS edges '
                .'JOIN main.graph_nodes AS from_nodes ON from_nodes.osm_node_id=edges.from_node_id '
                .'JOIN main.graph_nodes AS to_nodes ON to_nodes.osm_node_id=edges.to_node_id '
                .'ORDER BY edges.osm_way_id, edges.segment_index'
            );
            $this->sqlite->exec('CREATE VIRTUAL TABLE temp.route_edges_rtree USING rtree(id, min_longitude, max_longitude, min_latitude, max_latitude)');
            $this->sqlite->exec('INSERT INTO temp.route_edges_rtree SELECT id, min(from_longitude,to_longitude), max(from_longitude,to_longitude), min(from_latitude,to_latitude), max(from_latitude,to_latitude) FROM temp.route_edge_map');
            $this->buildComponents();
            $this->initialized = true;

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function buildComponents(): void
    {
        $parents = [];
        $ranks = [];
        foreach ($this->sqlite->query('SELECT osm_node_id FROM main.graph_nodes WHERE walkable=1 ORDER BY osm_node_id') as $row) {
            $nodeId = (int) $row['osm_node_id'];
            $parents[$nodeId] = $nodeId;
            $ranks[$nodeId] = 0;
        }
        $edges = $this->sqlite->query(
            'SELECT edges.from_node_id, edges.to_node_id FROM main.graph_edges AS edges '
            .'JOIN main.graph_nodes AS from_nodes ON from_nodes.osm_node_id=edges.from_node_id AND from_nodes.walkable=1 '
            .'JOIN main.graph_nodes AS to_nodes ON to_nodes.osm_node_id=edges.to_node_id AND to_nodes.walkable=1 '
            .'ORDER BY edges.osm_way_id, edges.segment_index'
        );
        while ($edge = $edges->fetch(PDO::FETCH_ASSOC)) {
            $left = $this->findRoot($parents, (int) $edge['from_node_id']);
            $right = $this->findRoot($parents, (int) $edge['to_node_id']);
            if ($left === $right) {
                continue;
            }
            if ($ranks[$left] < $ranks[$right] || ($ranks[$left] === $ranks[$right] && $left > $right)) {
                [$left, $right] = [$right, $left];
            }
            $parents[$right] = $left;
            if ($ranks[$left] === $ranks[$right]) {
                $ranks[$left]++;
            }
        }

        $minimumByRoot = [];
        $sizeByRoot = [];
        foreach (array_keys($parents) as $nodeId) {
            $root = $this->findRoot($parents, $nodeId);
            $minimumByRoot[$root] = min($minimumByRoot[$root] ?? $nodeId, $nodeId);
            $sizeByRoot[$root] = ($sizeByRoot[$root] ?? 0) + 1;
        }
        foreach (array_keys($parents) as $nodeId) {
            $root = $this->findRoot($parents, $nodeId);
            $componentId = $minimumByRoot[$root];
            $this->componentByNode[$nodeId] = $componentId;
        }
        foreach ($sizeByRoot as $root => $size) {
            $componentId = $minimumByRoot[$root];
            $this->componentSizes[$componentId] = $size;
            if ($this->largestComponentId === null
                || $size > $this->componentSizes[$this->largestComponentId]
                || ($size === $this->componentSizes[$this->largestComponentId] && $componentId < $this->largestComponentId)) {
                $this->largestComponentId = $componentId;
            }
        }
    }

    /** @param array<int, int> $parents */
    private function findRoot(array &$parents, int $nodeId): int
    {
        $root = $nodeId;
        while ($parents[$root] !== $root) {
            $root = $parents[$root];
        }
        while ($parents[$nodeId] !== $nodeId) {
            $parent = $parents[$nodeId];
            $parents[$nodeId] = $root;
            $nodeId = $parent;
        }

        return $root;
    }

    private function connectorCrossesBarrier(array $point, array $node): bool
    {
        $latitudeDelta = 1.5 / 111_320;
        $longitudeDelta = 1.5 / max(1, 111_320 * cos(deg2rad((float) $point['lat'])));
        $statement = $this->sqlite->prepare(
            'SELECT nodes.latitude, nodes.longitude FROM temp.route_nodes_rtree AS indexed '
            .'JOIN main.graph_nodes AS nodes ON nodes.osm_node_id=indexed.id '
            .'WHERE nodes.walkable=0 AND indexed.min_longitude<=? AND indexed.max_longitude>=? '
            .'AND indexed.min_latitude<=? AND indexed.max_latitude>=?'
        );
        $statement->execute([
            max((float) $point['lng'], $node['longitude']) + $longitudeDelta,
            min((float) $point['lng'], $node['longitude']) - $longitudeDelta,
            max((float) $point['lat'], $node['latitude']) + $latitudeDelta,
            min((float) $point['lat'], $node['latitude']) - $latitudeDelta,
        ]);
        $scale = 111_320;
        $cosine = cos(deg2rad(((float) $point['lat'] + $node['latitude']) / 2));
        $startX = (float) $point['lng'] * $scale * $cosine;
        $startY = (float) $point['lat'] * $scale;
        $endX = $node['longitude'] * $scale * $cosine;
        $endY = $node['latitude'] * $scale;
        $dx = $endX - $startX;
        $dy = $endY - $startY;
        $lengthSquared = $dx * $dx + $dy * $dy;
        while ($barrier = $statement->fetch(PDO::FETCH_ASSOC)) {
            $barrierX = (float) $barrier['longitude'] * $scale * $cosine;
            $barrierY = (float) $barrier['latitude'] * $scale;
            $fraction = $lengthSquared <= 0 ? 0 : (($barrierX - $startX) * $dx + ($barrierY - $startY) * $dy) / $lengthSquared;
            $fraction = min(1, max(0, $fraction));
            $distance = hypot($barrierX - ($startX + $fraction * $dx), $barrierY - ($startY + $fraction * $dy));
            if ($distance <= 1.5) {
                $statement->closeCursor();

                return true;
            }
        }
        $statement->closeCursor();

        return false;
    }

    private function routeEdges(array $origin, array $destination, float $maximumDistanceMeters): ?array
    {
        $middleLatitude = ((float) $origin['lat'] + (float) $destination['lat']) / 2;
        $latitudeDelta = $maximumDistanceMeters / 111_320;
        $longitudeDelta = $maximumDistanceMeters / max(1, 111_320 * cos(deg2rad($middleLatitude)));
        $minimumLatitude = min((float) $origin['lat'], (float) $destination['lat']) - $latitudeDelta;
        $maximumLatitude = max((float) $origin['lat'], (float) $destination['lat']) + $latitudeDelta;
        $minimumLongitude = min((float) $origin['lng'], (float) $destination['lng']) - $longitudeDelta;
        $maximumLongitude = max((float) $origin['lng'], (float) $destination['lng']) + $longitudeDelta;
        $statement = $this->sqlite->prepare(
            'SELECT edge.id, edge.way_id, edge.segment_index, edge.from_node_id, edge.to_node_id, edge.bidirectional, '
            .'edge.length_meters, edge.from_latitude, edge.from_longitude, edge.to_latitude, edge.to_longitude '
            .'FROM temp.route_edges_rtree AS indexed JOIN temp.route_edge_map AS edge ON edge.id=indexed.id '
            .'WHERE indexed.max_longitude>=? AND indexed.min_longitude<=? '
            .'AND indexed.max_latitude>=? AND indexed.min_latitude<=? '
            .'ORDER BY edge.way_id, edge.segment_index LIMIT ?'
        );
        $statement->execute([$minimumLongitude, $maximumLongitude, $minimumLatitude, $maximumLatitude, $this->maximumEdgesPerRoute + 1]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (count($rows) > $this->maximumEdgesPerRoute) {
            return null;
        }

        return $rows;
    }

    /** @return array{array<int, list<array<string, int|float>>>, array<int, array{0: float, 1: float}>} */
    private function adjacency(array $edges): array
    {
        $adjacency = [];
        $coordinates = [];
        foreach ($edges as $edge) {
            $from = (int) $edge['from_node_id'];
            $to = (int) $edge['to_node_id'];
            $length = (float) $edge['length_meters'];
            if ($from === $to || ! is_finite($length) || $length <= 0 || $length > 10_000) {
                continue;
            }
            $coordinates[$from] = [(float) $edge['from_longitude'], (float) $edge['from_latitude']];
            $coordinates[$to] = [(float) $edge['to_longitude'], (float) $edge['to_latitude']];
            $forward = [
                'to' => $to, 'length' => $length, 'way_id' => (int) $edge['way_id'],
                'segment_index' => (int) $edge['segment_index'],
            ];
            $adjacency[$from][] = $forward;
            if ((int) $edge['bidirectional'] === 1) {
                $adjacency[$to][] = [
                    'to' => $from, 'length' => $length, 'way_id' => (int) $edge['way_id'],
                    'segment_index' => (int) $edge['segment_index'],
                ];
            }
        }
        foreach ($adjacency as &$neighbors) {
            usort($neighbors, static fn (array $left, array $right): int => ($left['to'] <=> $right['to'])
                ?: (($left['way_id'] <=> $right['way_id']) ?: ($left['segment_index'] <=> $right['segment_index'])));
        }
        unset($neighbors);

        return [$adjacency, $coordinates];
    }

    /** @return array{path: list<int>, edges: list<array<string, int|float>>, network_distance: float, maximum_edge_meters: float, limited: bool, distance_bound_reached: bool} */
    private function shortestPath(int $start, int $destination, array $adjacency, float $maximumDistanceMeters): array
    {
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $queue->insert($start, [0.0, -$start]);
        $distances = [$start => 0.0];
        $parents = [];
        $parentEdges = [];
        $visited = 0;
        $distanceBoundReached = false;

        while (! $queue->isEmpty()) {
            $item = $queue->extract();
            $nodeId = (int) $item['data'];
            $distance = $distances[$nodeId] ?? INF;
            if (-((float) $item['priority'][0]) > $distance + 0.001) {
                continue;
            }
            if (++$visited > $this->maximumVisitedNodesPerRoute) {
                return ['path' => [], 'edges' => [], 'network_distance' => 0.0, 'maximum_edge_meters' => 0.0, 'limited' => true, 'distance_bound_reached' => $distanceBoundReached];
            }
            if ($nodeId === $destination) {
                break;
            }
            foreach ($adjacency[$nodeId] ?? [] as $edge) {
                $nextDistance = $distance + (float) $edge['length'];
                if ($nextDistance > $maximumDistanceMeters) {
                    $distanceBoundReached = true;

                    continue;
                }
                $nextNode = (int) $edge['to'];
                $current = $distances[$nextNode] ?? INF;
                $shorter = $nextDistance < $current - 0.001;
                $stableTie = abs($nextDistance - $current) <= 0.001 && $nodeId < ($parents[$nextNode] ?? PHP_INT_MAX);
                if (! $shorter && ! $stableTie) {
                    continue;
                }
                $distances[$nextNode] = $nextDistance;
                $parents[$nextNode] = $nodeId;
                $parentEdges[$nextNode] = $edge;
                $queue->insert($nextNode, [-$nextDistance, -$nextNode]);
            }
        }

        if (! isset($distances[$destination])) {
            return ['path' => [], 'edges' => [], 'network_distance' => 0.0, 'maximum_edge_meters' => 0.0, 'limited' => false, 'distance_bound_reached' => $distanceBoundReached];
        }
        $path = [$destination];
        $edges = [];
        $current = $destination;
        while ($current !== $start) {
            if (! isset($parents[$current], $parentEdges[$current]) || count($path) > $this->maximumVisitedNodesPerRoute) {
                return ['path' => [], 'edges' => [], 'network_distance' => 0.0, 'maximum_edge_meters' => 0.0, 'limited' => true, 'distance_bound_reached' => $distanceBoundReached];
            }
            $edges[] = [
                'way_id' => (int) $parentEdges[$current]['way_id'],
                'segment_index' => (int) $parentEdges[$current]['segment_index'],
                'length_meters' => (float) $parentEdges[$current]['length'],
            ];
            $current = $parents[$current];
            $path[] = $current;
        }
        $path = array_reverse($path);
        $edges = array_reverse($edges);
        $maximumEdgeMeters = 0.0;
        foreach ($edges as $edge) {
            $maximumEdgeMeters = max($maximumEdgeMeters, (float) $edge['length_meters']);
        }

        return [
            'path' => $path,
            'edges' => $edges,
            'network_distance' => (float) $distances[$destination],
            'maximum_edge_meters' => $maximumEdgeMeters,
            'limited' => false,
            'distance_bound_reached' => $distanceBoundReached,
        ];
    }

    private function insideScope(array $point): bool
    {
        $bounds = $this->metadata['source_bounds'] ?? [];

        return isset($bounds['min_latitude'], $bounds['max_latitude'], $bounds['min_longitude'], $bounds['max_longitude'])
            && (float) $point['lat'] >= (float) $bounds['min_latitude']
            && (float) $point['lat'] <= (float) $bounds['max_latitude']
            && (float) $point['lng'] >= (float) $bounds['min_longitude']
            && (float) $point['lng'] <= (float) $bounds['max_longitude'];
    }

    private function validPoint(array $point): bool
    {
        return isset($point['lat'], $point['lng'])
            && is_numeric($point['lat']) && is_numeric($point['lng'])
            && is_finite((float) $point['lat']) && is_finite((float) $point['lng'])
            && (float) $point['lat'] >= -90 && (float) $point['lat'] <= 90
            && (float) $point['lng'] >= -180 && (float) $point['lng'] <= 180;
    }

    private function candidateBefore(array $candidate, array $current): bool
    {
        return $candidate['distance_meters'] < $current['distance_meters'] - 0.000001
            || (abs($candidate['distance_meters'] - $current['distance_meters']) <= 0.000001 && $candidate['node_id'] < $current['node_id']);
    }

    private function distanceMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadius = 6_371_000;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    private function hasDuplicateGeometryPoints(array $coordinates): bool
    {
        for ($index = 1; $index < count($coordinates); $index++) {
            if ($coordinates[$index] === $coordinates[$index - 1]) {
                return true;
            }
        }

        return false;
    }

    private function unavailable(string $reasonCode, array $extra = []): array
    {
        return [
            'status' => 'ROUTE_UNAVAILABLE',
            'reason_code' => $reasonCode,
            'distance_meters' => null,
            'estimated_duration_seconds' => null,
            'geometry' => null,
            'source' => null,
            'source_sha256' => $this->expectedSourceSha256,
            'graph_fingerprint' => $this->metadata['graph_content_sha256'] ?? null,
            ...$extra,
        ];
    }
}
