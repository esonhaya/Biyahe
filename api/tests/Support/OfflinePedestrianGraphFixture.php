<?php

namespace Tests\Support;

use App\Services\Routing\OfflinePedestrianGraphRouter;
use PDO;
use RuntimeException;

final class OfflinePedestrianGraphFixture
{
    public const SOURCE_SHA256 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function create(array $nodes, array $edges): string
    {
        $path = tempnam(sys_get_temp_dir(), 'offline-walk-graph-');
        if ($path === false) {
            throw new RuntimeException('Could not allocate an isolated SQLite graph fixture.');
        }
        $sqlite = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sqlite->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE graph_nodes (osm_node_id INTEGER PRIMARY KEY, latitude REAL NOT NULL, longitude REAL NOT NULL, in_scope INTEGER NOT NULL, walkable INTEGER NOT NULL, barrier TEXT NULL, crossing TEXT NULL)');
        $sqlite->exec('CREATE TABLE graph_edges (osm_way_id INTEGER NOT NULL, segment_index INTEGER NOT NULL, from_node_id INTEGER NOT NULL, to_node_id INTEGER NOT NULL, bidirectional INTEGER NOT NULL, length_meters REAL NOT NULL, highway TEXT NOT NULL, properties TEXT NOT NULL, geometry_wkt TEXT NOT NULL, PRIMARY KEY (osm_way_id,segment_index))');
        $metadata = [
            'source_sha256' => self::SOURCE_SHA256,
            'state' => 'COMPLETE',
            'input_complete' => true,
            'source_bounds' => ['min_latitude' => 10.2, 'max_latitude' => 10.4, 'min_longitude' => 123.8, 'max_longitude' => 124.0],
            'graph_content_sha256' => str_repeat('c', 64),
        ];
        $insertMetadata = $sqlite->prepare('INSERT INTO metadata (key, value) VALUES (?, ?)');
        foreach ($metadata as $key => $value) {
            $insertMetadata->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
        }
        $insertNode = $sqlite->prepare('INSERT INTO graph_nodes (osm_node_id, latitude, longitude, in_scope, walkable, barrier, crossing) VALUES (?, ?, ?, 1, ?, ?, ?)');
        foreach ($nodes as $node) {
            $insertNode->execute($node);
        }
        $insertEdge = $sqlite->prepare('INSERT INTO graph_edges (osm_way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, highway, properties, geometry_wkt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($edges as $edge) {
            $insertEdge->execute([...$edge, 'footway', '{}', 'LINESTRING(0 0,0 0)']);
        }
        unset($sqlite);

        return $path;
    }

    public static function validation(): array
    {
        return [
            'artifact_ready' => true,
            'routing_ready' => true,
            'source_sha256' => self::SOURCE_SHA256,
            'routing_capabilities' => [
                'complete_restriction_inventory' => true,
                'routing_ready' => true,
            ],
        ];
    }

    public static function router(string $path, int $maximumEdges = 50_000, float $maximumSnapDistance = 25): OfflinePedestrianGraphRouter
    {
        return new OfflinePedestrianGraphRouter($path, self::SOURCE_SHA256, self::validation(), $maximumSnapDistance, $maximumEdges, 20_000);
    }

    public static function remove(string $path): void
    {
        @unlink($path);
    }
}
