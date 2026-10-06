<?php

namespace App\Services\Routing;

use App\Enums\PedestrianAccessClassification;
use App\Enums\PedestrianRouteStatus;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SplPriorityQueue;
use Throwable;

final class LocalPedestrianGraphRouter implements PedestrianRouter
{
    /** @var array<string, PedestrianRouteResult> */
    private array $routeCache = [];

    private int $candidates = 0;

    private int $routesComputed = 0;

    private int $routesAvailable = 0;

    private int $cacheHits = 0;

    private int $noRouteResults = 0;

    private float $routingMilliseconds = 0;

    private string $providerStatus = 'UNAVAILABLE';

    private bool $schemaChecked = false;

    private bool $schemaAvailable = false;

    private bool $datasetLoaded = false;

    private ?object $dataset = null;

    private int $nextVirtualNodeId = -1;

    public function route(PedestrianRouteRequest $request): PedestrianRouteResult
    {
        $this->candidates++;
        $dataset = $this->activeDataset();
        $datasetKey = (string) ($dataset->stable_id ?? 'no-active-dataset');
        $cacheKey = $request->cacheKey($datasetKey);
        if (isset($this->routeCache[$cacheKey])) {
            $this->cacheHits++;

            return $this->routeCache[$cacheKey];
        }

        $this->routesComputed++;
        $startedAt = hrtime(true);
        try {
            $result = $this->calculate($request, $dataset);
        } catch (Throwable) {
            $this->providerStatus = 'UNAVAILABLE';
            $result = $this->result(
                PedestrianRouteStatus::UNAVAILABLE,
                $request,
                ['The local pedestrian graph could not be queried. A straight-line estimate is shown instead.'],
                $dataset,
            );
        } finally {
            $this->routingMilliseconds += (hrtime(true) - $startedAt) / 1_000_000;
        }
        if ($result->status->isAvailable()) {
            $this->routesAvailable++;
        }
        if (in_array($result->status, [PedestrianRouteStatus::NO_ROUTE, PedestrianRouteStatus::DISTANCE_LIMIT], true)) {
            $this->noRouteResults++;
        }

        $maximumCacheEntries = max(1, (int) config('biyahe.pedestrian.maximum_cached_routes', 512));
        if (count($this->routeCache) >= $maximumCacheEntries) {
            array_shift($this->routeCache);
        }
        $this->routeCache[$cacheKey] = $result;

        return $result;
    }

    public function metrics(): array
    {
        return [
            'candidates' => $this->candidates,
            'routes_computed' => $this->routesComputed,
            'routes_available' => $this->routesAvailable,
            'route_failures' => $this->routesComputed - $this->routesAvailable,
            'cache_hits' => $this->cacheHits,
            'no_route_results' => $this->noRouteResults,
            'routing_time_milliseconds' => round($this->routingMilliseconds, 2),
            'provider_status' => $this->providerStatus,
        ];
    }

    /** @return array<string, mixed>|null */
    private function activeDataset(): ?object
    {
        if (! $this->schemaChecked) {
            $this->schemaAvailable = Schema::hasTable('pedestrian_network_datasets')
                && Schema::hasTable('pedestrian_network_nodes')
                && Schema::hasTable('pedestrian_network_edges');
            $this->schemaChecked = true;
        }
        if (! $this->schemaAvailable) {
            $this->providerStatus = 'SCHEMA_MISSING';

            return null;
        }
        if (! $this->datasetLoaded) {
            $this->dataset = DB::table('pedestrian_network_datasets')->where('is_active', true)->first();
            $this->datasetLoaded = true;
            $this->providerStatus = $this->dataset === null ? 'NO_ACTIVE_DATASET' : 'ACTIVE_DATASET';
        }

        return $this->dataset;
    }

    private function calculate(PedestrianRouteRequest $request, ?object $dataset): PedestrianRouteResult
    {
        if (config('biyahe.pedestrian.provider') !== 'local_osm_graph') {
            $this->providerStatus = 'INVALID_PROVIDER';

            return $this->result(
                PedestrianRouteStatus::UNAVAILABLE,
                $request,
                ['No supported pedestrian-routing provider is configured.'],
                $dataset,
            );
        }
        if ($dataset === null) {
            return $this->result(
                PedestrianRouteStatus::UNAVAILABLE,
                $request,
                ['No local OpenStreetMap pedestrian graph is installed; distance is a straight-line estimate, not a walking route.'],
                null,
            );
        }

        if (! $this->validPoint($request->origin) || ! $this->validPoint($request->destination)
            || ! is_finite($request->maximumDistanceMeters) || $request->maximumDistanceMeters <= 0) {
            $this->providerStatus = 'INVALID_REQUEST';

            return $this->result(PedestrianRouteStatus::UNAVAILABLE, $request, ['The pedestrian request is outside supported bounds.'], $dataset);
        }
        if (! $this->insideDataset($request->origin, $dataset) || ! $this->insideDataset($request->destination, $dataset)) {
            $this->providerStatus = 'OUT_OF_COVERAGE';

            return $this->result(
                PedestrianRouteStatus::OUT_OF_COVERAGE,
                $request,
                ['One or both mapped points are outside the installed pedestrian-network extract.'],
                $dataset,
            );
        }

        $this->nextVirtualNodeId = -1;
        $snapDistance = (float) config('biyahe.pedestrian.maximum_snap_distance_meters', 25);
        $startCandidates = $this->nearbyNodes($request->origin, (int) $dataset->id, $snapDistance);
        $endCandidates = $this->nearbyNodes($request->destination, (int) $dataset->id, $snapDistance);
        $originBarrierBlocked = false;
        $destinationBarrierBlocked = false;
        $startNodes = $startCandidates === []
            ? $this->nearbyEdgeAnchors($request->origin, (int) $dataset->id, $originBarrierBlocked)
            : $this->safeSnapNodes($request->origin, $startCandidates, (int) $dataset->id, $originBarrierBlocked);
        $endNodes = $endCandidates === []
            ? $this->nearbyEdgeAnchors($request->destination, (int) $dataset->id, $destinationBarrierBlocked)
            : $this->safeSnapNodes($request->destination, $endCandidates, (int) $dataset->id, $destinationBarrierBlocked);
        if ($startNodes === [] || $endNodes === []) {
            $this->providerStatus = 'ACTIVE_DATASET';
            $warning = $originBarrierBlocked || $destinationBarrierBlocked
                ? 'Connecting a reference point to the graph would cross a mapped barrier; no walkable access route is claimed.'
                : 'No mapped pedestrian node is close enough to one or both reference points; no route is claimed.';

            return $this->result(
                PedestrianRouteStatus::UNAVAILABLE,
                $request,
                [$warning],
                $dataset,
                $originBarrierBlocked || $destinationBarrierBlocked
                    ? PedestrianAccessClassification::NO_WALKABLE_ACCESS
                    : PedestrianAccessClassification::GRAPH_COVERAGE_GAP,
            );
        }

        $edges = $this->nearbyEdges($request, $dataset, $startNodes, $endNodes);
        if ($edges === null) {
            $this->providerStatus = 'SEARCH_BOUND';

            return $this->result(
                PedestrianRouteStatus::UNAVAILABLE,
                $request,
                ['The bounded local graph search exceeded its configured edge limit.'],
                $dataset,
            );
        }

        [$adjacency, $coordinates] = $this->adjacency($edges);
        $this->addEdgeAnchors($adjacency, $coordinates, [...$startNodes, ...$endNodes]);
        $destinationNodeIds = array_fill_keys(array_map(static fn (array $node): int => $node['id'], $endNodes), true);
        $best = null;
        $distanceLimited = false;
        $searchLimited = false;
        foreach ($startNodes as $startNode) {
            if (! isset($adjacency[$startNode['id']])) {
                continue;
            }
            $search = $this->shortestPaths(
                $adjacency,
                $startNode['id'],
                $destinationNodeIds,
                (float) $request->maximumDistanceMeters,
            );
            $searchLimited = $searchLimited || $search['limited'];
            $distanceLimited = $distanceLimited || $search['distance_limited'];

            foreach ($endNodes as $endNode) {
                $networkDistance = $search['distances'][$endNode['id']] ?? null;
                if ($networkDistance === null) {
                    continue;
                }
                $totalDistance = $networkDistance + $startNode['snap_meters'] + $endNode['snap_meters'];
                if ($totalDistance > $request->maximumDistanceMeters) {
                    $distanceLimited = true;

                    continue;
                }
                if ($best !== null && $totalDistance >= $best['total_distance']) {
                    continue;
                }

                $nodeIds = $this->pathNodeIds($startNode['id'], $endNode['id'], $search['parents']);
                $path = array_values(array_filter(array_map(
                    static fn (int $nodeId): ?array => $coordinates[$nodeId] ?? null,
                    $nodeIds,
                )));
                if (count($path) === 1) {
                    $path[] = $path[0];
                }
                if (count($path) < 2) {
                    continue;
                }
                $best = [
                    'total_distance' => $totalDistance,
                    'network_distance' => $networkDistance,
                    'start_snap' => $startNode['snap_meters'],
                    'end_snap' => $endNode['snap_meters'],
                    'path' => $path,
                ];
            }
        }

        if ($best !== null) {
            $partial = $best['start_snap'] > 1 || $best['end_snap'] > 1;
            $warnings = ['Path follows OSM ways treated as pedestrian-accessible; mapped road centerlines may not represent a sidewalk, and temporary or on-the-ground conditions may be incomplete.'];
            if ($partial) {
                $warnings[] = 'Short straight-line connectors to the graph are approximate and are not part of the displayed network geometry; they do not establish safe access where barriers or paths are unmapped.';
            }
            $this->providerStatus = 'ACTIVE_DATASET';

            return new PedestrianRouteResult(
                status: $partial ? PedestrianRouteStatus::PARTIAL : PedestrianRouteStatus::AVAILABLE,
                origin: $request->origin,
                destination: $request->destination,
                accessClassification: PedestrianAccessClassification::NETWORK_ROUTE,
                distanceMeters: $best['network_distance'],
                estimatedDurationSeconds: (int) round($best['total_distance'] / max(0.1, (float) config('biyahe.pedestrian.walking_speed_meters_per_second', 1.2))),
                geometry: ['type' => 'LineString', 'coordinates' => $best['path']],
                source: 'OPENSTREETMAP',
                dataset: (string) $dataset->stable_id,
                originSnapDistanceMeters: $best['start_snap'],
                destinationSnapDistanceMeters: $best['end_snap'],
                warnings: $warnings,
            );
        }

        $this->providerStatus = $searchLimited ? 'SEARCH_BOUND' : 'ACTIVE_DATASET';
        if ($searchLimited) {
            return $this->result(
                PedestrianRouteStatus::UNAVAILABLE,
                $request,
                ['The bounded pedestrian search reached its node limit; a path could not be determined.'],
                $dataset,
            );
        }
        if ($distanceLimited) {
            return $this->result(
                PedestrianRouteStatus::DISTANCE_LIMIT,
                $request,
                ['A pedestrian route was not found within the configured walking-distance bound.'],
                $dataset,
            );
        }

        return $this->result(
            PedestrianRouteStatus::NO_ROUTE,
            $request,
            ['No connection is mapped in the current OSM pedestrian extract; map coverage may be incomplete.'],
            $dataset,
        );
    }

    /** @param array{lat: float, lng: float} $point @return list<array<string, mixed>> */
    private function nearbyNodes(array $point, int $datasetId, float $maximumSnapMeters): array
    {
        $latitudeDelta = $maximumSnapMeters / 111_320;
        $longitudeDelta = $maximumSnapMeters / max(1, 111_320 * cos(deg2rad($point['lat'])));
        $rows = DB::table('pedestrian_network_nodes')
            ->where('dataset_id', $datasetId)
            ->where('is_walkable', true)
            ->whereBetween('latitude', [$point['lat'] - $latitudeDelta, $point['lat'] + $latitudeDelta])
            ->whereBetween('longitude', [$point['lng'] - $longitudeDelta, $point['lng'] + $longitudeDelta])
            ->get(['id', 'latitude', 'longitude']);

        $nodes = [];
        foreach ($rows as $row) {
            $latitude = (float) $row->latitude;
            $longitude = (float) $row->longitude;
            $distance = Geo::distanceMeters($point['lat'], $point['lng'], $latitude, $longitude);
            if ($distance <= $maximumSnapMeters) {
                $nodes[] = ['id' => (int) $row->id, 'lat' => $latitude, 'lng' => $longitude, 'snap_meters' => $distance];
            }
        }
        usort($nodes, static fn (array $left, array $right): int => ($left['snap_meters'] <=> $right['snap_meters']) ?: ($left['id'] <=> $right['id']));

        return array_slice($nodes, 0, max(1, (int) config('biyahe.pedestrian.maximum_snap_candidates', 6)));
    }

    /** @param array{lat: float, lng: float} $point @param list<array<string, mixed>> $candidates @return list<array<string, mixed>> */
    private function safeSnapNodes(array $point, array $candidates, int $datasetId, bool &$barrierBlocked): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $candidates;
        }

        $safe = [];
        foreach ($candidates as $candidate) {
            if ($candidate['snap_meters'] > 1 && $this->connectorCrossesMappedBarrier($point, $candidate, $datasetId)) {
                $barrierBlocked = true;

                continue;
            }
            $safe[] = $candidate;
        }

        return $safe;
    }

    /** @param array{lat: float, lng: float} $point @param array<string, mixed> $node */
    private function connectorCrossesMappedBarrier(array $point, array $node, int $datasetId): bool
    {
        return DB::table('pedestrian_network_nodes')
            ->where('dataset_id', $datasetId)
            ->where('is_walkable', false)
            ->whereRaw(
                'ST_DWithin(location, ST_SetSRID(ST_MakeLine(ST_MakePoint(?, ?), ST_MakePoint(?, ?)), 4326)::geography, 1.5)',
                [$point['lng'], $point['lat'], $node['lng'], $node['lat']],
            )
            ->exists();
    }

    /** @param array{lat: float, lng: float} $point @param-out bool $barrierBlocked @return list<array<string, mixed>> */
    private function nearbyEdgeAnchors(array $point, int $datasetId, bool &$barrierBlocked): array
    {
        $barrierBlocked = false;
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $maximumMeters = (float) config('biyahe.pedestrian.maximum_edge_anchor_distance_meters', 10);
        if (! is_finite($maximumMeters) || $maximumMeters <= 0) {
            return [];
        }
        $latitudeDelta = $maximumMeters / 111_320;
        $longitudeDelta = $maximumMeters / max(1, 111_320 * cos(deg2rad($point['lat'])));
        $pointExpression = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)';
        $rows = DB::table('pedestrian_network_edges as edges')
            ->join('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
            ->join('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
            ->where('edges.dataset_id', $datasetId)
            ->where('from_nodes.is_walkable', true)
            ->where('to_nodes.is_walkable', true)
            ->where('edges.max_latitude', '>=', $point['lat'] - $latitudeDelta)
            ->where('edges.min_latitude', '<=', $point['lat'] + $latitudeDelta)
            ->where('edges.max_longitude', '>=', $point['lng'] - $longitudeDelta)
            ->where('edges.min_longitude', '<=', $point['lng'] + $longitudeDelta)
            ->whereRaw("ST_DWithin(edges.geometry::geography, {$pointExpression}::geography, ?)", [$point['lng'], $point['lat'], $maximumMeters])
            ->select([
                'edges.id', 'edges.from_node_id', 'edges.to_node_id', 'edges.bidirectional',
                'edges.length_meters', 'edges.highway', 'edges.properties',
            ])
            ->selectRaw("ST_Distance(edges.geometry::geography, {$pointExpression}::geography) AS anchor_distance", [$point['lng'], $point['lat']])
            ->selectRaw("ST_X(ST_ClosestPoint(edges.geometry, {$pointExpression})) AS anchor_longitude", [$point['lng'], $point['lat']])
            ->selectRaw("ST_Y(ST_ClosestPoint(edges.geometry, {$pointExpression})) AS anchor_latitude", [$point['lng'], $point['lat']])
            ->selectRaw("ST_LineLocatePoint(edges.geometry, {$pointExpression}) AS anchor_fraction", [$point['lng'], $point['lat']])
            ->orderByRaw("ST_Distance(edges.geometry::geography, {$pointExpression}::geography)", [$point['lng'], $point['lat']])
            ->limit(24)
            ->get();

        $rows = $rows->sortBy(fn (object $row): float => (float) $row->anchor_distance)->values();
        foreach ($rows as $row) {
            $properties = is_string($row->properties) ? json_decode($row->properties, true) : $row->properties;
            $properties = is_array($properties) ? $properties : [];
            if (! $this->edgeAllowsReferenceAccess((string) $row->highway, $properties)) {
                continue;
            }
            $anchor = [
                'id' => $this->nextVirtualNodeId--,
                'lat' => (float) $row->anchor_latitude,
                'lng' => (float) $row->anchor_longitude,
                'snap_meters' => (float) $row->anchor_distance,
                'is_anchor' => true,
                'from_node_id' => (int) $row->from_node_id,
                'to_node_id' => (int) $row->to_node_id,
                'bidirectional' => (bool) $row->bidirectional,
                'length_meters' => (float) $row->length_meters,
                'fraction' => min(1, max(0, (float) $row->anchor_fraction)),
            ];
            if ($this->connectorCrossesMappedBarrier($point, $anchor, $datasetId)) {
                $barrierBlocked = true;

                continue;
            }

            return [$anchor];
        }

        return [];
    }

    /** @param array<string, mixed> $properties */
    private function edgeAllowsReferenceAccess(string $highway, array $properties): bool
    {
        $highway = strtolower(trim($highway));
        $foot = strtolower(trim((string) ($properties['foot'] ?? '')));
        $access = strtolower(trim((string) ($properties['access'] ?? '')));
        $sidewalk = strtolower(trim((string) ($properties['sidewalk'] ?? '')));
        $blocked = ['no', 'private', 'customers', 'permit', 'use_sidepath'];
        if (in_array($foot, $blocked, true) || in_array($access, $blocked, true)) {
            return false;
        }
        if (in_array($foot, ['yes', 'designated', 'permissive', 'official'], true)
            || in_array($access, ['yes', 'designated', 'permissive', 'official'], true)) {
            return true;
        }
        if (in_array($sidewalk, ['yes', 'both', 'left', 'right', 'continuous'], true)) {
            return true;
        }

        return in_array($highway, ['footway', 'path', 'pedestrian', 'steps', 'living_street', 'residential'], true);
    }

    /** @param list<array{id: int, lat: float, lng: float, snap_meters: float}> $startNodes @param list<array{id: int, lat: float, lng: float, snap_meters: float}> $endNodes @return list<object>|null */
    private function nearbyEdges(PedestrianRouteRequest $request, object $dataset, array $startNodes, array $endNodes): ?array
    {
        $marginMeters = (float) config('biyahe.pedestrian.search_margin_meters', 1800);
        $latitudeDelta = $marginMeters / 111_320;
        $middleLatitude = ($request->origin['lat'] + $request->destination['lat']) / 2;
        $longitudeDelta = $marginMeters / max(1, 111_320 * cos(deg2rad($middleLatitude)));
        $coordinates = [
            $request->origin['lat'], $request->destination['lat'], ...array_column($startNodes, 'lat'), ...array_column($endNodes, 'lat'),
        ];
        $longitudes = [
            $request->origin['lng'], $request->destination['lng'], ...array_column($startNodes, 'lng'), ...array_column($endNodes, 'lng'),
        ];
        $minimumLatitude = min($coordinates) - $latitudeDelta;
        $maximumLatitude = max($coordinates) + $latitudeDelta;
        $minimumLongitude = min($longitudes) - $longitudeDelta;
        $maximumLongitude = max($longitudes) + $longitudeDelta;
        $limit = max(1, (int) config('biyahe.pedestrian.maximum_edges_per_route', 50000));
        $edges = DB::table('pedestrian_network_edges as edges')
            ->join('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
            ->join('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
            ->where('edges.dataset_id', (int) $dataset->id)
            ->where('edges.max_latitude', '>=', $minimumLatitude)
            ->where('edges.min_latitude', '<=', $maximumLatitude)
            ->where('edges.max_longitude', '>=', $minimumLongitude)
            ->where('edges.min_longitude', '<=', $maximumLongitude)
            ->orderBy('edges.id')
            ->limit($limit + 1)
            ->get([
                'edges.from_node_id', 'edges.to_node_id', 'edges.bidirectional', 'edges.length_meters',
                'from_nodes.latitude as from_latitude', 'from_nodes.longitude as from_longitude', 'from_nodes.is_walkable as from_walkable',
                'to_nodes.latitude as to_latitude', 'to_nodes.longitude as to_longitude', 'to_nodes.is_walkable as to_walkable',
            ]);

        return $edges->count() > $limit ? null : $edges->all();
    }

    /** @param list<object> $edges @return array{array<int, list<array{to: int, length: float}>>, array<int, array{0: float, 1: float}>} */
    private function adjacency(array $edges): array
    {
        $adjacency = [];
        $coordinates = [];
        foreach ($edges as $edge) {
            if (! (bool) $edge->from_walkable || ! (bool) $edge->to_walkable) {
                continue;
            }
            $from = (int) $edge->from_node_id;
            $to = (int) $edge->to_node_id;
            $length = (float) $edge->length_meters;
            if ($from === $to || ! is_finite($length) || $length <= 0 || $length > 10000) {
                continue;
            }
            $coordinates[$from] = [(float) $edge->from_longitude, (float) $edge->from_latitude];
            $coordinates[$to] = [(float) $edge->to_longitude, (float) $edge->to_latitude];
            $adjacency[$from][] = ['to' => $to, 'length' => $length];
            if ((bool) $edge->bidirectional) {
                $adjacency[$to][] = ['to' => $from, 'length' => $length];
            }
        }
        foreach ($adjacency as &$neighbors) {
            usort($neighbors, static fn (array $left, array $right): int => $left['to'] <=> $right['to']);
        }
        unset($neighbors);

        return [$adjacency, $coordinates];
    }

    /** @param array<int, list<array{to: int, length: float}>> $adjacency @param array<int, array{0: float, 1: float}> $coordinates @param list<array<string, mixed>> $nodes */
    private function addEdgeAnchors(array &$adjacency, array &$coordinates, array $nodes): void
    {
        foreach ($nodes as $node) {
            if (($node['is_anchor'] ?? false) !== true) {
                continue;
            }
            $virtual = (int) $node['id'];
            $from = (int) $node['from_node_id'];
            $to = (int) $node['to_node_id'];
            $length = (float) $node['length_meters'];
            $fraction = (float) $node['fraction'];
            $fromLength = max(0.01, $length * $fraction);
            $toLength = max(0.01, $length * (1 - $fraction));
            $coordinates[$virtual] = [(float) $node['lng'], (float) $node['lat']];
            $adjacency[$from][] = ['to' => $virtual, 'length' => $fromLength];
            $adjacency[$virtual][] = ['to' => $to, 'length' => $toLength];
            if ((bool) $node['bidirectional']) {
                $adjacency[$to][] = ['to' => $virtual, 'length' => $toLength];
                $adjacency[$virtual][] = ['to' => $from, 'length' => $fromLength];
            }
        }
        foreach ($adjacency as &$neighbors) {
            usort($neighbors, static fn (array $left, array $right): int => $left['to'] <=> $right['to']);
        }
        unset($neighbors);
    }

    /** @param array<int, list<array{to: int, length: float}>> $adjacency @param array<int, true> $destinations @return array{distances: array<int, float>, parents: array<int, int>, limited: bool, distance_limited: bool} */
    private function shortestPaths(array $adjacency, int $start, array $destinations, float $maximumDistance): array
    {
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $queue->insert($start, 0);
        $distances = [$start => 0.0];
        $parents = [];
        $remainingDestinations = $destinations;
        unset($remainingDestinations[$start]);
        $visited = 0;
        $maximumVisited = max(1, (int) config('biyahe.pedestrian.maximum_visited_nodes_per_route', 20000));
        $distanceLimited = false;

        while (! $queue->isEmpty() && $remainingDestinations !== []) {
            $item = $queue->extract();
            $node = (int) $item['data'];
            $distance = $distances[$node] ?? INF;
            if (-((float) $item['priority']) > $distance + 0.01) {
                continue;
            }
            if (++$visited > $maximumVisited) {
                return ['distances' => $distances, 'parents' => $parents, 'limited' => true, 'distance_limited' => $distanceLimited];
            }
            if (isset($remainingDestinations[$node])) {
                unset($remainingDestinations[$node]);
            }
            foreach ($adjacency[$node] ?? [] as $neighbor) {
                $nextDistance = $distance + $neighbor['length'];
                if ($nextDistance > $maximumDistance) {
                    $distanceLimited = true;

                    continue;
                }
                $neighborId = $neighbor['to'];
                $existingDistance = $distances[$neighborId] ?? INF;
                $shorter = $nextDistance < $existingDistance - 0.001;
                $stableTie = abs($nextDistance - $existingDistance) <= 0.001
                    && $node < ($parents[$neighborId] ?? PHP_INT_MAX);
                if (! $shorter && ! $stableTie) {
                    continue;
                }
                $distances[$neighborId] = $nextDistance;
                $parents[$neighborId] = $node;
                $queue->insert($neighborId, -$nextDistance);
            }
        }

        return ['distances' => $distances, 'parents' => $parents, 'limited' => false, 'distance_limited' => $distanceLimited];
    }

    /** @param array<int, int> $parents @return list<int> */
    private function pathNodeIds(int $start, int $end, array $parents): array
    {
        $path = [$end];
        $node = $end;
        $limit = count($parents) + 1;
        while ($node !== $start && isset($parents[$node]) && $limit-- > 0) {
            $node = $parents[$node];
            $path[] = $node;
        }
        if ($node !== $start) {
            return [];
        }

        return array_reverse($path);
    }

    /** @param array{lat: float, lng: float} $point */
    private function validPoint(array $point): bool
    {
        return isset($point['lat'], $point['lng'])
            && is_finite((float) $point['lat']) && is_finite((float) $point['lng'])
            && (float) $point['lat'] >= -90 && (float) $point['lat'] <= 90
            && (float) $point['lng'] >= -180 && (float) $point['lng'] <= 180;
    }

    /** @param array{lat: float, lng: float} $point */
    private function insideDataset(array $point, object $dataset): bool
    {
        return $point['lat'] >= (float) $dataset->min_latitude && $point['lat'] <= (float) $dataset->max_latitude
            && $point['lng'] >= (float) $dataset->min_longitude && $point['lng'] <= (float) $dataset->max_longitude;
    }

    /** @param array{lat: float, lng: float} $point */
    private function result(
        PedestrianRouteStatus $status,
        PedestrianRouteRequest $request,
        array $warnings,
        ?object $dataset,
        ?PedestrianAccessClassification $accessClassification = null,
    ): PedestrianRouteResult {
        return new PedestrianRouteResult(
            status: $status,
            origin: $request->origin,
            destination: $request->destination,
            accessClassification: $accessClassification ?? match ($status) {
                PedestrianRouteStatus::NO_ROUTE, PedestrianRouteStatus::DISTANCE_LIMIT, PedestrianRouteStatus::OUT_OF_COVERAGE => PedestrianAccessClassification::GRAPH_COVERAGE_GAP,
                default => PedestrianAccessClassification::ROUTING_UNAVAILABLE,
            },
            source: $dataset === null ? null : 'OPENSTREETMAP',
            dataset: $dataset === null ? null : (string) $dataset->stable_id,
            warnings: $warnings,
        );
    }
}
