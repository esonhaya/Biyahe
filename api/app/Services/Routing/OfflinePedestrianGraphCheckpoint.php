<?php

namespace App\Services\Routing;

use PDO;
use RuntimeException;

/** Verifies durable source and graph phase checkpoints before reuse or publication. */
final class OfflinePedestrianGraphCheckpoint
{
    public const FORMAT = 'biyahe-pedestrian-sqlite-v3';

    public const SCHEMA_VERSION = 3;

    private const LEGACY_FORMAT = 'biyahe-pedestrian-sqlite-v2';

    private const LEGACY_SCHEMA_VERSION = 2;

    /** @param array<string, mixed> $sourceIdentity */
    public function upgradeOrVerify(PDO $sqlite, array $sourceIdentity): void
    {
        $this->assertIntegrity($sqlite);
        $metadata = $this->metadata($sqlite);
        $format = $metadata['format'] ?? null;
        $schemaVersion = (int) ($metadata['schema_version'] ?? 0);

        $this->assertSourceIdentity($metadata, $sourceIdentity);

        if ($format === self::LEGACY_FORMAT && $schemaVersion === self::LEGACY_SCHEMA_VERSION) {
            $sqlite->beginTransaction();
            try {
                $sqlite->exec('CREATE TABLE IF NOT EXISTS phase_checkpoints (phase TEXT PRIMARY KEY, source_sha256 TEXT NOT NULL, source_snapshot TEXT NULL, schema_version INTEGER NOT NULL, phase_identity TEXT NOT NULL, completion_state TEXT NOT NULL, record_counts TEXT NOT NULL, output_fingerprint TEXT NOT NULL)');
                $this->setMeta($sqlite, 'format', self::FORMAT);
                $this->setMeta($sqlite, 'schema_version', self::SCHEMA_VERSION);
                $this->setMeta($sqlite, 'extract_strategy', 'offline_scope_first_sqlite_v3');

                if (($metadata['way_index_complete'] ?? false) === true
                    && ($metadata['source_pass_producer_exit'] ?? null) === 'PASS') {
                    $this->record($sqlite, $sourceIdentity, 'way_index');
                }
                $legacyReferencesComplete = ($metadata['input_complete'] ?? false) === true
                    && ($metadata['references_complete'] ?? false) === true
                    && ($metadata['resolution_xml_complete'] ?? false) === true
                    && ($metadata['resolution_producer_exit'] ?? null) === 'PASS';
                $restrictionDetailsComplete = $this->count($sqlite, 'SELECT COUNT(*) FROM source_restrictions')
                    === (int) ($metadata['relation_restrictions'] ?? -1);
                if ($legacyReferencesComplete && $restrictionDetailsComplete) {
                    $this->record($sqlite, $sourceIdentity, 'reference_resolution');
                }
                if ($legacyReferencesComplete && ! $restrictionDetailsComplete) {
                    $sqlite->exec('DELETE FROM resolved_node_ids');
                    $sqlite->exec('DELETE FROM source_nodes WHERE in_scope=0');
                    $sqlite->exec('DELETE FROM graph_edges');
                    $sqlite->exec('DELETE FROM graph_nodes');
                    $sqlite->exec("DELETE FROM phase_checkpoints WHERE phase IN ('reference_resolution', 'graph_build')");
                    foreach (['resolved_nodes', 'indexed_source_nodes', 'graph_nodes', 'graph_edges', 'component_count', 'graph_content_sha256', 'accepted_ways', 'restricted_ways'] as $key) {
                        $sqlite->exec('DELETE FROM metadata WHERE key='.$sqlite->quote($key));
                    }
                    $this->setMeta($sqlite, 'references_complete', false);
                    $this->setMeta($sqlite, 'input_complete', false);
                    $this->setMeta($sqlite, 'resolution_xml_complete', false);
                    $this->setMeta($sqlite, 'resolution_producer_exit', 'NOT_RECORDED');
                    $this->setMeta($sqlite, 'state', 'WAY_INDEX_COMPLETE');
                    $metadata['state'] = 'WAY_INDEX_COMPLETE';
                }
                if (($metadata['state'] ?? null) === 'COMPLETE' && $legacyReferencesComplete && $restrictionDetailsComplete) {
                    $this->record($sqlite, $sourceIdentity, 'graph_build');
                }
                $sqlite->commit();
            } catch (\Throwable $exception) {
                if ($sqlite->inTransaction()) {
                    $sqlite->rollBack();
                }
                throw $exception;
            }
            $metadata = $this->metadata($sqlite);
        } elseif ($format !== self::FORMAT || $schemaVersion !== self::SCHEMA_VERSION) {
            throw new RuntimeException('Offline checkpoint format or schema version is unsupported.');
        }

        if (! $this->tableExists($sqlite, 'phase_checkpoints')) {
            throw new RuntimeException('Offline checkpoint phase table is missing.');
        }

        $state = $metadata['state'] ?? null;
        $this->assertKnownPhases($sqlite);

        if (in_array($state, [
            'WAY_INDEX_COMPLETE', 'RESOLVING_REFERENCES', 'RESOLUTION_PENDING_PRODUCER',
            'REFERENCE_RESOLUTION_INCOMPLETE', 'RESOLUTION_COMPLETE', 'SOURCE_COMPLETE',
            'GRAPH_BUILDING', 'COMPLETE',
        ], true)) {
            $this->verify($sqlite, $sourceIdentity, 'way_index');
        }
        if (in_array($state, ['SOURCE_COMPLETE', 'GRAPH_BUILDING', 'COMPLETE'], true)) {
            $this->verify($sqlite, $sourceIdentity, 'reference_resolution');
        }
        if ($state === 'COMPLETE') {
            $this->verify($sqlite, $sourceIdentity, 'graph_build');
        }
    }

    /** @param array<string, mixed> $sourceIdentity */
    public function record(PDO $sqlite, array $sourceIdentity, string $phase): void
    {
        $this->assertPhaseComplete($sqlite, $phase);
        $counts = $this->counts($sqlite, $phase);
        $statement = $sqlite->prepare('INSERT INTO phase_checkpoints (phase, source_sha256, source_snapshot, schema_version, phase_identity, completion_state, record_counts, output_fingerprint) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(phase) DO UPDATE SET source_sha256=excluded.source_sha256, source_snapshot=excluded.source_snapshot, schema_version=excluded.schema_version, phase_identity=excluded.phase_identity, completion_state=excluded.completion_state, record_counts=excluded.record_counts, output_fingerprint=excluded.output_fingerprint');
        $statement->execute([
            $phase,
            $sourceIdentity['sha256'],
            $sourceIdentity['timestamp'],
            self::SCHEMA_VERSION,
            $this->phaseIdentity($phase),
            'COMPLETE',
            json_encode($counts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->fingerprint($sqlite, $phase),
        ]);
    }

    /** @param array<string, mixed> $sourceIdentity */
    public function verify(PDO $sqlite, array $sourceIdentity, string $phase): void
    {
        $statement = $sqlite->prepare('SELECT source_sha256, source_snapshot, schema_version, phase_identity, completion_state, record_counts, output_fingerprint FROM phase_checkpoints WHERE phase=?');
        $statement->execute([$phase]);
        $checkpoint = $statement->fetch(PDO::FETCH_ASSOC);
        if (! is_array($checkpoint)
            || ! hash_equals((string) $sourceIdentity['sha256'], (string) $checkpoint['source_sha256'])
            || $checkpoint['source_snapshot'] !== $sourceIdentity['timestamp']
            || (int) $checkpoint['schema_version'] !== self::SCHEMA_VERSION
            || $checkpoint['phase_identity'] !== $this->phaseIdentity($phase)
            || $checkpoint['completion_state'] !== 'COMPLETE') {
            throw new RuntimeException('Offline '.$phase.' checkpoint provenance or completion state is invalid.');
        }

        try {
            $storedCounts = json_decode((string) $checkpoint['record_counts'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Offline '.$phase.' checkpoint counts are malformed.', 0, $exception);
        }

        if ($storedCounts !== $this->counts($sqlite, $phase)
            || ! hash_equals((string) $checkpoint['output_fingerprint'], $this->fingerprint($sqlite, $phase))) {
            $fingerprintLabel = $phase === 'graph_build' ? 'content fingerprint' : 'output fingerprint';
            throw new RuntimeException('Offline '.$phase.' checkpoint counts or '.$fingerprintLabel.' do not match its committed rows.');
        }
    }

    public function assertIntegrity(PDO $sqlite): void
    {
        $integrity = (string) $sqlite->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new RuntimeException('Offline SQLite checkpoint integrity check failed: '.$integrity);
        }
    }

    /** @param array<string, mixed> $sourceIdentity */
    public function verifyCompleteArtifact(PDO $sqlite, array $sourceIdentity): void
    {
        $metadata = $this->metadata($sqlite);
        if (($metadata['format'] ?? null) !== self::FORMAT
            || (int) ($metadata['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('Offline artifact checkpoint schema is unsupported.');
        }
        $this->assertSourceIdentity($metadata, $sourceIdentity);
        $this->assertKnownPhases($sqlite);
        foreach (['way_index', 'reference_resolution', 'graph_build'] as $phase) {
            $this->verify($sqlite, $sourceIdentity, $phase);
        }
    }

    private function assertSourceIdentity(array $metadata, array $sourceIdentity): void
    {
        foreach ($sourceIdentity as $key => $value) {
            if (($metadata['source_identity_'.$key] ?? null) !== $value) {
                throw new RuntimeException('Resume source identity does not match the offline checkpoint.');
            }
        }
    }

    private function assertPhaseComplete(PDO $sqlite, string $phase): void
    {
        $metadata = $this->metadata($sqlite);
        $complete = match ($phase) {
            'way_index' => ($metadata['way_index_complete'] ?? false) === true
                && ($metadata['source_pass_producer_exit'] ?? null) === 'PASS',
            'reference_resolution' => ($metadata['references_complete'] ?? false) === true
                && ($metadata['input_complete'] ?? false) === true
                && ($metadata['resolution_xml_complete'] ?? false) === true
                && ($metadata['resolution_producer_exit'] ?? null) === 'PASS',
            'graph_build' => ($metadata['state'] ?? null) === 'COMPLETE',
            default => false,
        };

        if (! $complete) {
            throw new RuntimeException('Cannot checkpoint an incomplete offline '.$phase.' phase.');
        }
    }

    private function counts(PDO $sqlite, string $phase): array
    {
        $metadata = $this->metadata($sqlite);

        return match ($phase) {
            'way_index' => $this->wayIndexCounts($sqlite, $metadata),
            'reference_resolution' => $this->referenceCounts($sqlite, $metadata),
            'graph_build' => $this->graphCounts($sqlite, $metadata),
            default => throw new RuntimeException('Unknown offline checkpoint phase.'),
        };
    }

    private function wayIndexCounts(PDO $sqlite, array $metadata): array
    {
        $scopeNodes = $this->count($sqlite, 'SELECT COUNT(*) FROM source_nodes WHERE in_scope=1');
        $counts = [
            'source_nodes' => (int) ($metadata['source_nodes'] ?? -1),
            'scope_nodes' => $scopeNodes,
            'source_ways' => $this->count($sqlite, 'SELECT COUNT(*) FROM seen_way_ids'),
            'candidate_ways' => $this->count($sqlite, 'SELECT COUNT(*) FROM source_ways'),
            'relations' => $this->count($sqlite, 'SELECT COUNT(*) FROM seen_relation_ids'),
            'restriction_relations' => (int) ($metadata['relation_restrictions'] ?? -1),
            'way_references' => $this->count($sqlite, 'SELECT COUNT(*) FROM way_refs'),
            'required_nodes' => $this->count($sqlite, 'SELECT COUNT(*) FROM required_nodes'),
        ];
        if ($counts['source_nodes'] < $scopeNodes
            || $scopeNodes !== (int) ($metadata['scope_nodes'] ?? -1)
            || $counts['source_ways'] !== (int) ($metadata['source_ways'] ?? -1)
            || $counts['candidate_ways'] !== (int) ($metadata['candidate_ways'] ?? -1)
            || $counts['relations'] !== (int) ($metadata['relations'] ?? -1)
            || $counts['required_nodes'] !== (int) ($metadata['required_nodes'] ?? -1)
            || $counts['restriction_relations'] < 0
            || $counts['way_references'] < 1
            || $this->count($sqlite, 'SELECT COUNT(*) FROM (SELECT way_id FROM way_refs GROUP BY way_id HAVING MIN(sequence_index)<>0 OR MAX(sequence_index)+1<>COUNT(*))') !== 0
            || $this->count($sqlite, 'SELECT COUNT(*) FROM (SELECT id FROM source_ways EXCEPT SELECT way_id FROM way_refs)') !== 0
            || $this->count($sqlite, 'SELECT COUNT(*) FROM (SELECT way_id FROM way_refs EXCEPT SELECT id FROM source_ways)') !== 0
            || $this->setMismatch($sqlite, 'SELECT node_id FROM way_refs', 'SELECT id FROM required_nodes') !== 0) {
            throw new RuntimeException('Offline way-index checkpoint counts do not match the committed source rows.');
        }

        return $counts;
    }

    private function referenceCounts(PDO $sqlite, array $metadata): array
    {
        $requiredNodes = $this->count($sqlite, 'SELECT COUNT(*) FROM required_nodes');
        $resolvedNodes = $this->count($sqlite, 'SELECT COUNT(*) FROM resolved_node_ids');
        $indexedNodes = $this->count($sqlite, 'SELECT COUNT(*) FROM source_nodes');
        $counts = [
            'required_nodes' => $requiredNodes,
            'resolved_nodes' => $resolvedNodes,
            'indexed_source_nodes' => $indexedNodes,
            'out_of_scope_nodes' => $this->count($sqlite, 'SELECT COUNT(*) FROM source_nodes WHERE in_scope=0'),
            'way_references' => $this->count($sqlite, 'SELECT COUNT(*) FROM way_refs'),
            'restriction_relations' => $this->count($sqlite, 'SELECT COUNT(*) FROM source_restrictions'),
            'restriction_members' => $this->count($sqlite, 'SELECT COUNT(*) FROM source_restriction_members'),
        ];
        if ($requiredNodes === 0 || $resolvedNodes !== $requiredNodes
            || $resolvedNodes !== (int) ($metadata['resolved_nodes'] ?? -1)
            || $indexedNodes !== (int) ($metadata['indexed_source_nodes'] ?? -1)
            || $counts['restriction_relations'] !== (int) ($metadata['relation_restrictions'] ?? -1)
            || $this->setMismatch($sqlite, 'SELECT id FROM required_nodes', 'SELECT id FROM resolved_node_ids') !== 0
            || $this->count($sqlite, 'SELECT COUNT(*) FROM (SELECT refs.node_id FROM way_refs AS refs LEFT JOIN source_nodes AS nodes ON nodes.id=refs.node_id WHERE nodes.id IS NULL)') !== 0) {
            throw new RuntimeException('Offline reference checkpoint counts do not match the committed node rows.');
        }
        if ($this->count($sqlite, 'SELECT COUNT(*) FROM (SELECT relation_id FROM source_restriction_members GROUP BY relation_id HAVING MIN(sequence_index)<>0 OR MAX(sequence_index)+1<>COUNT(*))') !== 0
            || $this->count($sqlite, 'SELECT COUNT(*) FROM (SELECT restrictions.relation_id FROM source_restrictions AS restrictions LEFT JOIN (SELECT relation_id, COUNT(*) AS member_count FROM source_restriction_members GROUP BY relation_id) AS members ON members.relation_id=restrictions.relation_id WHERE restrictions.member_count<>COALESCE(members.member_count, 0))') !== 0) {
            throw new RuntimeException('Offline restriction dependency counts do not match the committed relation members.');
        }
        $this->assertRestrictionFingerprints($sqlite);

        return $counts;
    }

    private function assertRestrictionFingerprints(PDO $sqlite): void
    {
        $restrictions = $sqlite->query('SELECT relation_id, member_count, members_sha256 FROM source_restrictions ORDER BY relation_id');
        $members = $sqlite->prepare('SELECT sequence_index, member_type, member_ref, role FROM source_restriction_members WHERE relation_id=? ORDER BY sequence_index');
        while ($restriction = $restrictions->fetch(PDO::FETCH_ASSOC)) {
            $members->execute([(int) $restriction['relation_id']]);
            $hash = hash_init('sha256');
            $count = 0;
            while ($member = $members->fetch(PDO::FETCH_NUM)) {
                hash_update($hash, json_encode($member, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
                $count++;
            }
            $members->closeCursor();
            if ($count !== (int) $restriction['member_count']
                || ! hash_equals((string) $restriction['members_sha256'], hash_final($hash))) {
                throw new RuntimeException('Offline restriction dependency fingerprint does not match its member rows.');
            }
        }
    }

    private function graphCounts(PDO $sqlite, array $metadata): array
    {
        $counts = [
            'graph_nodes' => $this->count($sqlite, 'SELECT COUNT(*) FROM graph_nodes'),
            'graph_edges' => $this->count($sqlite, 'SELECT COUNT(*) FROM graph_edges'),
            'components' => (int) ($metadata['component_count'] ?? -1),
            'accepted_ways' => (int) ($metadata['accepted_ways'] ?? -1),
            'restricted_ways' => (int) ($metadata['restricted_ways'] ?? -1),
        ];
        if ($counts['graph_nodes'] < 1 || $counts['graph_edges'] < 1 || $counts['components'] < 1
            || $counts['graph_nodes'] !== (int) ($metadata['graph_nodes'] ?? -1)
            || $counts['graph_edges'] !== (int) ($metadata['graph_edges'] ?? -1)) {
            throw new RuntimeException('Offline graph checkpoint counts do not match the committed graph rows.');
        }

        return $counts;
    }

    private function fingerprint(PDO $sqlite, string $phase): string
    {
        if ($phase === 'graph_build') {
            return OfflinePedestrianGraphTopology::contentHash($sqlite);
        }

        $queries = $phase === 'way_index'
            ? [
                'SELECT id, latitude, longitude, walkable, barrier, crossing FROM source_nodes WHERE in_scope=1 ORDER BY id',
                'SELECT id FROM seen_way_ids ORDER BY id',
                'SELECT id FROM seen_relation_ids ORDER BY id',
                'SELECT id, tags FROM source_ways ORDER BY id',
                'SELECT way_id, sequence_index, node_id FROM way_refs ORDER BY way_id, sequence_index',
                'SELECT id FROM required_nodes ORDER BY id',
            ]
            : [
                'SELECT id, latitude, longitude, in_scope, walkable, barrier, crossing FROM source_nodes ORDER BY id',
                'SELECT id FROM resolved_node_ids ORDER BY id',
                'SELECT relation_id, tags, member_count, members_sha256 FROM source_restrictions ORDER BY relation_id',
                'SELECT relation_id, sequence_index, member_type, member_ref, role FROM source_restriction_members ORDER BY relation_id, sequence_index',
            ];
        $hash = hash_init('sha256');
        foreach ($queries as $index => $query) {
            hash_update($hash, "TABLE\0".$index."\0");
            foreach ($sqlite->query($query, PDO::FETCH_NUM) as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            }
        }

        return hash_final($hash);
    }

    private function phaseIdentity(string $phase): string
    {
        return match ($phase) {
            'way_index' => 'way-index-v3',
            'reference_resolution' => 'reference-resolution-v3',
            'graph_build' => 'graph-build-v3',
            default => throw new RuntimeException('Unknown offline checkpoint phase.'),
        };
    }

    private function assertKnownPhases(PDO $sqlite): void
    {
        $known = ['way_index', 'reference_resolution', 'graph_build'];
        foreach ($sqlite->query('SELECT phase FROM phase_checkpoints') as $row) {
            if (! in_array((string) $row['phase'], $known, true)) {
                throw new RuntimeException('Offline checkpoint contains an unknown phase identity.');
            }
        }
    }

    private function tableExists(PDO $sqlite, string $table): bool
    {
        $statement = $sqlite->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    private function count(PDO $sqlite, string $query): int
    {
        return (int) $sqlite->query($query)->fetchColumn();
    }

    private function setMismatch(PDO $sqlite, string $leftQuery, string $rightQuery): int
    {
        return $this->count($sqlite, 'SELECT COUNT(*) FROM ('.$leftQuery.' EXCEPT '.$rightQuery.')')
            + $this->count($sqlite, 'SELECT COUNT(*) FROM ('.$rightQuery.' EXCEPT '.$leftQuery.')');
    }

    private function metadata(PDO $sqlite): array
    {
        $metadata = [];
        foreach ($sqlite->query('SELECT key, value FROM metadata') as $row) {
            $metadata[(string) $row['key']] = json_decode((string) $row['value'], true, 32, JSON_THROW_ON_ERROR);
        }

        return $metadata;
    }

    private function setMeta(PDO $sqlite, string $key, mixed $value): void
    {
        $statement = $sqlite->prepare('INSERT INTO metadata (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        $statement->execute([$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }
}
