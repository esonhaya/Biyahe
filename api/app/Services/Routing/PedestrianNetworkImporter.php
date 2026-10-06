<?php

namespace App\Services\Routing;

use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

final class PedestrianNetworkImporter
{
    private const ATTRIBUTION = '© OpenStreetMap contributors';

    private const LICENSE = 'ODbL-1.0';

    private const WALKABLE_HIGHWAYS = [
        'footway', 'path', 'pedestrian', 'steps', 'living_street', 'residential',
        'unclassified', 'service', 'track', 'tertiary', 'secondary', 'primary',
        'tertiary_link', 'secondary_link', 'primary_link',
    ];

    public function import(string $path, string $sourceUrl): PedestrianNetworkImportResult
    {
        $maximumBytes = max(1024, (int) config('biyahe.pedestrian.maximum_input_bytes', 20_000_000));
        if (! is_file($path) || ! is_readable($path) || filesize($path) === false || filesize($path) > $maximumBytes) {
            throw new InvalidArgumentException('Input must be a readable JSON file within the configured byte limit.');
        }
        if (! $this->validSourceUrl($sourceUrl)) {
            throw new InvalidArgumentException('Source URL must be a valid HTTP or HTTPS URL.');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || strlen($contents) > $maximumBytes) {
            throw new InvalidArgumentException('Input must be a readable JSON file within the configured byte limit.');
        }
        try {
            $document = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Input is not valid bounded Overpass JSON.');
        }
        if (! is_array($document) || ! is_array($document['elements'] ?? null)) {
            throw new InvalidArgumentException('Input must contain an Overpass elements array.');
        }

        [$nodes, $ways] = $this->parseElements($document['elements']);
        [$selectedNodes, $edges] = $this->buildGraph($nodes, $ways);
        if ($selectedNodes === [] || $edges === []) {
            throw new InvalidArgumentException('Input contains no usable pedestrian network inside the configured Cebu scope.');
        }

        $contentHash = hash('sha256', $contents);
        $stableId = 'osm-cebu-walk-'.substr($contentHash, 0, 32);
        $sourceTimestamp = $document['osm3s']['timestamp_osm_base'] ?? null;
        $sourceTimestamp = is_string($sourceTimestamp) ? $this->validTimestamp($sourceTimestamp) : null;
        $bounds = $this->bounds($selectedNodes);

        return DB::transaction(function () use ($stableId, $sourceUrl, $sourceTimestamp, $contentHash, $bounds, $selectedNodes, $edges): PedestrianNetworkImportResult {
            $existing = DB::table('pedestrian_network_datasets')->where('content_hash', $contentHash)->first();
            if ($existing !== null) {
                DB::table('pedestrian_network_datasets')->update(['is_active' => false, 'updated_at' => now()]);
                DB::table('pedestrian_network_datasets')->where('id', $existing->id)->update(['is_active' => true, 'updated_at' => now()]);

                return new PedestrianNetworkImportResult($existing->stable_id, (int) $existing->node_count, (int) $existing->edge_count, true);
            }

            $datasetId = DB::table('pedestrian_network_datasets')->insertGetId([
                'stable_id' => $stableId,
                'source' => 'OPENSTREETMAP',
                'source_url' => $sourceUrl,
                'license' => self::LICENSE,
                'attribution' => self::ATTRIBUTION,
                'source_timestamp' => $sourceTimestamp,
                'imported_at' => now(),
                'min_latitude' => $bounds['min_latitude'],
                'min_longitude' => $bounds['min_longitude'],
                'max_latitude' => $bounds['max_latitude'],
                'max_longitude' => $bounds['max_longitude'],
                'node_count' => count($selectedNodes),
                'edge_count' => count($edges),
                'content_hash' => $contentHash,
                'metadata' => json_encode([
                    'format' => 'overpass-json',
                    'extract_scope' => config('biyahe.pedestrian.coverage'),
                    'importer' => 'local-pedestrian-network-v1',
                ], JSON_THROW_ON_ERROR),
                'is_active' => false,
                'graph_retained' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $nodeIds = [];
            foreach (array_chunk($selectedNodes, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $osmId => $node) {
                    $location = DB::connection()->getDriverName() === 'pgsql'
                        ? DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%.7F,%.7F),4326)::geography', $node['lng'], $node['lat']))
                        : json_encode(['type' => 'Point', 'coordinates' => [$node['lng'], $node['lat']]], JSON_THROW_ON_ERROR);
                    $rows[] = [
                        'dataset_id' => $datasetId,
                        'osm_node_id' => $osmId,
                        'latitude' => $node['lat'],
                        'longitude' => $node['lng'],
                        'is_walkable' => $node['walkable'],
                        'barrier' => $node['barrier'],
                        'location' => $location,
                    ];
                }
                DB::table('pedestrian_network_nodes')->insert($rows);
            }
            $nodeIds = DB::table('pedestrian_network_nodes')->where('dataset_id', $datasetId)->pluck('id', 'osm_node_id')->all();

            foreach (array_chunk($edges, 400) as $chunk) {
                $rows = [];
                foreach ($chunk as $edge) {
                    $from = $selectedNodes[$edge['from']];
                    $to = $selectedNodes[$edge['to']];
                    $geometry = DB::connection()->getDriverName() === 'pgsql'
                        ? DB::raw(sprintf('ST_GeomFromText(\'LINESTRING(%.7F %.7F,%.7F %.7F)\',4326)', $from['lng'], $from['lat'], $to['lng'], $to['lat']))
                        : json_encode(['type' => 'LineString', 'coordinates' => [[$from['lng'], $from['lat']], [$to['lng'], $to['lat']]]], JSON_THROW_ON_ERROR);
                    $rows[] = [
                        'stable_id' => $stableId.'-'.$edge['way'].'-'.$edge['segment'],
                        'dataset_id' => $datasetId,
                        'osm_way_id' => $edge['way'],
                        'segment_index' => $edge['segment'],
                        'from_node_id' => $nodeIds[$edge['from']],
                        'to_node_id' => $nodeIds[$edge['to']],
                        'bidirectional' => $edge['bidirectional'],
                        'length_meters' => round($edge['length'], 2),
                        'highway' => $edge['highway'],
                        'properties' => json_encode($edge['properties'], JSON_THROW_ON_ERROR),
                        'min_latitude' => min($from['lat'], $to['lat']),
                        'min_longitude' => min($from['lng'], $to['lng']),
                        'max_latitude' => max($from['lat'], $to['lat']),
                        'max_longitude' => max($from['lng'], $to['lng']),
                        'geometry' => $geometry,
                    ];
                }
                DB::table('pedestrian_network_edges')->insert($rows);
            }

            DB::table('pedestrian_network_datasets')->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('pedestrian_network_datasets')->where('id', $datasetId)->update(['is_active' => true, 'updated_at' => now()]);

            return new PedestrianNetworkImportResult($stableId, count($selectedNodes), count($edges), false);
        });
    }

    /** @return array{array<int, array{lat: float, lng: float, walkable: bool, barrier: ?string}>, array<int, array{nodes: list<int>, tags: array<string, string>}>} */
    private function parseElements(array $elements): array
    {
        $maxNodes = max(1, (int) config('biyahe.pedestrian.maximum_input_nodes', 50000));
        $maxWays = max(1, (int) config('biyahe.pedestrian.maximum_input_ways', 25000));
        $nodes = [];
        $ways = [];
        foreach ($elements as $element) {
            if (! is_array($element) || ! is_int($element['id'] ?? null) || $element['id'] <= 0) {
                throw new InvalidArgumentException('Every OSM element must have a positive integer ID.');
            }
            if (($element['type'] ?? null) === 'node') {
                if (count($nodes) >= $maxNodes) {
                    throw new InvalidArgumentException('OSM input exceeds the configured node limit.');
                }
                $latitude = $element['lat'] ?? null;
                $longitude = $element['lon'] ?? null;
                if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
                    || (float) $latitude < -90 || (float) $latitude > 90 || (float) $longitude < -180 || (float) $longitude > 180) {
                    throw new InvalidArgumentException('OSM nodes require valid WGS84 coordinates.');
                }
                if (isset($nodes[$element['id']])) {
                    throw new InvalidArgumentException('Duplicate OSM node ID in input.');
                }
                $tags = $this->tags($element['tags'] ?? []);
                $nodes[$element['id']] = [
                    'lat' => (float) $latitude,
                    'lng' => (float) $longitude,
                    'walkable' => ! $this->blockedNode($tags),
                    'barrier' => $tags['barrier'] ?? null,
                ];
            } elseif (($element['type'] ?? null) === 'way') {
                if (count($ways) >= $maxWays) {
                    throw new InvalidArgumentException('OSM input exceeds the configured way limit.');
                }
                if (isset($ways[$element['id']])) {
                    throw new InvalidArgumentException('Duplicate OSM way ID in input.');
                }
                $refs = $element['nodes'] ?? null;
                if (! is_array($refs) || count($refs) < 2 || array_filter($refs, static fn ($id): bool => ! is_int($id) || $id <= 0) !== []) {
                    throw new InvalidArgumentException('OSM ways require at least two positive node references.');
                }
                $ways[$element['id']] = ['nodes' => array_values($refs), 'tags' => $this->tags($element['tags'] ?? [])];
            }
        }

        return [$nodes, $ways];
    }

    /** @return array{array<int, array{lat: float, lng: float, walkable: bool, barrier: ?string}>, list<array<string, mixed>>} */
    private function buildGraph(array $nodes, array $ways): array
    {
        $scope = config('biyahe.pedestrian.coverage');
        $selectedNodes = [];
        $edges = [];
        foreach ($ways as $wayId => $way) {
            $tags = $way['tags'];
            if (! $this->walkableWay($tags)) {
                continue;
            }
            $refs = $way['nodes'];
            foreach ($refs as $reference) {
                if (! isset($nodes[$reference])) {
                    throw new InvalidArgumentException('A pedestrian way references a node missing from the extract. Request complete ways and nodes.');
                }
            }
            [$forward, $backward] = $this->directions($tags);
            if (! $forward && ! $backward) {
                continue;
            }
            $highway = $tags['highway'];
            $properties = array_intersect_key($tags, array_flip([
                'surface', 'smoothness', 'bridge', 'tunnel', 'layer', 'sidewalk', 'foot', 'access', 'oneway:foot', 'foot:forward', 'foot:backward',
            ]));
            for ($index = 0; $index < count($refs) - 1; $index++) {
                $fromId = $refs[$index];
                $toId = $refs[$index + 1];
                $from = $nodes[$fromId];
                $to = $nodes[$toId];
                if (! $from['walkable'] || ! $to['walkable'] || ! $this->insideScope($from, $scope) || ! $this->insideScope($to, $scope)) {
                    continue;
                }
                $length = Geo::distanceMeters($from['lat'], $from['lng'], $to['lat'], $to['lng']);
                if (! is_finite($length) || $length < 0.1 || $length > 10000) {
                    continue;
                }
                if (count($edges) >= max(1, (int) config('biyahe.pedestrian.maximum_input_edges', 100000))) {
                    throw new InvalidArgumentException('OSM input exceeds the configured pedestrian-edge limit.');
                }
                $selectedNodes[$fromId] = $from;
                $selectedNodes[$toId] = $to;
                if ($forward && $backward) {
                    $edges[] = ['way' => $wayId, 'segment' => $index, 'from' => $fromId, 'to' => $toId, 'bidirectional' => true, 'length' => $length, 'highway' => $highway, 'properties' => $properties];
                } elseif ($forward) {
                    $edges[] = ['way' => $wayId, 'segment' => $index, 'from' => $fromId, 'to' => $toId, 'bidirectional' => false, 'length' => $length, 'highway' => $highway, 'properties' => $properties];
                } else {
                    $edges[] = ['way' => $wayId, 'segment' => $index, 'from' => $toId, 'to' => $fromId, 'bidirectional' => false, 'length' => $length, 'highway' => $highway, 'properties' => $properties];
                }
            }
        }

        return [$selectedNodes, $edges];
    }

    private function walkableWay(array $tags): bool
    {
        $highway = $tags['highway'] ?? null;
        if (! is_string($highway) || ($tags['area'] ?? null) === 'yes'
            || in_array($highway, ['motorway', 'motorway_link', 'trunk', 'trunk_link', 'construction', 'proposed', 'raceway'], true)) {
            return false;
        }
        if (! in_array($highway, self::WALKABLE_HIGHWAYS, true)) {
            return false;
        }
        if (isset($tags['foot:conditional']) || isset($tags['access:conditional'])) {
            return false;
        }
        if (isset($tags['oneway:foot']) && ! in_array($tags['oneway:foot'], ['yes', 'no', '1', '0', 'true', 'false', '-1', 'reverse'], true)) {
            return false;
        }
        foreach (['foot:forward', 'foot:backward'] as $direction) {
            if (isset($tags[$direction]) && ! in_array($tags[$direction], ['yes', 'no', 'designated', 'official', 'permissive'], true)) {
                return false;
            }
        }
        $restricted = ['no', 'private', 'destination', 'customers', 'permit', 'agricultural', 'forestry', 'delivery', 'use_sidepath', 'discouraged'];
        if (in_array($tags['foot'] ?? null, $restricted, true)) {
            return false;
        }
        if (! isset($tags['foot']) && in_array($tags['access'] ?? null, $restricted, true)) {
            return false;
        }

        return true;
    }

    /** @return array{bool, bool} */
    private function directions(array $tags): array
    {
        $forward = true;
        $backward = true;
        if (in_array($tags['oneway:foot'] ?? null, ['yes', '1', 'true'], true)) {
            $backward = false;
        } elseif (in_array($tags['oneway:foot'] ?? null, ['-1', 'reverse'], true)) {
            $forward = false;
        }
        $restricted = ['no', 'private', 'destination', 'customers', 'permit', 'agricultural', 'forestry', 'delivery', 'use_sidepath', 'discouraged'];
        if (in_array($tags['foot:forward'] ?? null, $restricted, true)) {
            $forward = false;
        }
        if (in_array($tags['foot:backward'] ?? null, $restricted, true)) {
            $backward = false;
        }

        return [$forward, $backward];
    }

    private function blockedNode(array $tags): bool
    {
        $restricted = ['no', 'private', 'destination', 'customers', 'permit', 'agricultural', 'forestry', 'delivery', 'use_sidepath', 'discouraged'];
        if (isset($tags['foot:conditional']) || isset($tags['access:conditional'])) {
            return true;
        }
        if (in_array($tags['foot'] ?? null, $restricted, true)) {
            return true;
        }
        if (! isset($tags['foot']) && in_array($tags['access'] ?? null, $restricted, true)) {
            return true;
        }

        return in_array($tags['barrier'] ?? null, ['wall', 'fence', 'retaining_wall', 'ditch', 'hedge', 'block'], true);
    }

    private function insideScope(array $node, array $scope): bool
    {
        return $node['lat'] >= (float) $scope['min_latitude'] && $node['lat'] <= (float) $scope['max_latitude']
            && $node['lng'] >= (float) $scope['min_longitude'] && $node['lng'] <= (float) $scope['max_longitude'];
    }

    private function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            throw new InvalidArgumentException('OSM tags must be an object.');
        }
        $normalized = [];
        foreach ($tags as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new InvalidArgumentException('OSM tag names and values must be strings.');
            }
            $normalized[$key] = strtolower(trim($value));
        }

        return $normalized;
    }

    private function validSourceUrl(string $url): bool
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
    }

    private function validTimestamp(string $value): ?string
    {
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new InvalidArgumentException('OSM snapshot timestamp is malformed.');
        }
    }

    private function bounds(array $nodes): array
    {
        $latitudes = array_column($nodes, 'lat');
        $longitudes = array_column($nodes, 'lng');

        return [
            'min_latitude' => min($latitudes),
            'min_longitude' => min($longitudes),
            'max_latitude' => max($latitudes),
            'max_longitude' => max($longitudes),
        ];
    }
}
