<?php

namespace App\Services\Routing;

use PDO;
use RuntimeException;

/** Disk-backed weak-component and deterministic-content checks for offline artifacts. */
final class OfflinePedestrianGraphTopology
{
    public static function componentCount(PDO $sqlite): int
    {
        $sqlite->exec('DROP TABLE IF EXISTS temp.validation_parent');
        $sqlite->exec('CREATE TEMP TABLE validation_parent (node_id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, rank INTEGER NOT NULL)');
        $sqlite->exec('INSERT INTO validation_parent (node_id, parent_id, rank) SELECT osm_node_id, osm_node_id, 0 FROM graph_nodes');
        $findParent = $sqlite->prepare('SELECT parent_id FROM validation_parent WHERE node_id = ?');
        $getRank = $sqlite->prepare('SELECT rank FROM validation_parent WHERE node_id = ?');
        $setParent = $sqlite->prepare('UPDATE validation_parent SET parent_id = ? WHERE node_id = ?');
        $incrementRank = $sqlite->prepare('UPDATE validation_parent SET rank = rank + 1 WHERE node_id = ?');
        $root = static function (int $nodeId) use ($findParent): int {
            $current = $nodeId;
            while (true) {
                $findParent->execute([$current]);
                $parent = $findParent->fetchColumn();
                if ($parent === false) {
                    throw new RuntimeException('Offline graph edge references a node missing from the component index.');
                }
                $parent = (int) $parent;
                if ($parent === $current) {
                    return $current;
                }
                $current = $parent;
            }
        };
        $edges = $sqlite->query('SELECT from_node_id, to_node_id FROM graph_edges ORDER BY osm_way_id, segment_index');
        while ($edge = $edges->fetch(PDO::FETCH_ASSOC)) {
            $left = $root((int) $edge['from_node_id']);
            $right = $root((int) $edge['to_node_id']);
            if ($left === $right) {
                continue;
            }
            $getRank->execute([$left]);
            $leftRank = (int) $getRank->fetchColumn();
            $getRank->execute([$right]);
            $rightRank = (int) $getRank->fetchColumn();
            if ($leftRank < $rightRank || ($leftRank === $rightRank && $left > $right)) {
                [$left, $right] = [$right, $left];
                [$leftRank, $rightRank] = [$rightRank, $leftRank];
            }
            $setParent->execute([$left, $right]);
            if ($leftRank === $rightRank) {
                $incrementRank->execute([$left]);
            }
        }

        $nodes = $sqlite->query('SELECT node_id FROM validation_parent ORDER BY node_id');
        $compress = $sqlite->prepare('UPDATE validation_parent SET parent_id = ? WHERE node_id = ?');
        while (($nodeId = $nodes->fetchColumn()) !== false) {
            $nodeId = (int) $nodeId;
            $compress->execute([$root($nodeId), $nodeId]);
        }

        return (int) $sqlite->query('SELECT COUNT(*) FROM validation_parent WHERE parent_id = node_id')->fetchColumn();
    }

    public static function contentHash(PDO $sqlite): string
    {
        $hash = hash_init('sha256');
        foreach ($sqlite->query('SELECT osm_node_id, latitude, longitude, in_scope, walkable, barrier, crossing FROM graph_nodes ORDER BY osm_node_id') as $row) {
            hash_update($hash, "N\0".json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        }
        foreach ($sqlite->query('SELECT osm_way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, highway, properties, geometry_wkt FROM graph_edges ORDER BY osm_way_id, segment_index') as $row) {
            hash_update($hash, "E\0".json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        }

        return hash_final($hash);
    }
}
