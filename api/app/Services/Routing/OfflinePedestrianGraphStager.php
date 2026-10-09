<?php

namespace App\Services\Routing;

use App\Support\Geo;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use XMLReader;

/** Builds a crash-safe SQLite graph artifact without opening the application database. */
final class OfflinePedestrianGraphStager
{
    private const FORMAT = OfflinePedestrianGraphCheckpoint::FORMAT;

    private const SCHEMA_VERSION = OfflinePedestrianGraphCheckpoint::SCHEMA_VERSION;

    private const MAX_WAY_REFERENCES = 100_000;

    private const MAX_RELATION_MEMBERS = 100_000;

    private const COMMIT_EVERY_ELEMENTS = 25_000;

    /**
     * @param  array{url: string, sha256: string, timestamp: ?string, osmium_version: string}  $source
     * @return array<string, int|string|bool>
     */
    public function build(
        string $inputPath,
        string $outputPath,
        string $sourcePath,
        array $source,
        bool $resume = false,
        bool $stopAfterSource = false,
        bool $awaitProducerExit = false,
        bool $producerExitConfirmed = false,
        bool $resolveRequiredNodes = false,
        ?string $exportRequiredNodeIds = null,
        bool $resolveFullSource = false,
        bool $inspectCheckpoint = false,
    ): array {
        $sourceIdentity = $this->verifySource($sourcePath, $source);
        if ($outputPath === '' || ! is_dir(dirname($outputPath)) || ! is_writable(dirname($outputPath)) || file_exists($outputPath)) {
            throw new InvalidArgumentException('Output must be a new file in an existing writable directory.');
        }
        if ($inputPath !== '-' && (! is_file($inputPath) || ! is_readable($inputPath))) {
            throw new InvalidArgumentException('Input must be a readable OSM XML file or - for stdin.');
        }

        $workPath = $outputPath.'.building';
        if (file_exists($workPath) && ! $resume) {
            throw new InvalidArgumentException('A staging work file already exists; use --resume after checking its source identity.');
        }
        if (! file_exists($workPath)) {
            $reservation = @fopen($workPath, 'x');
            if ($reservation === false) {
                throw new RuntimeException('Could not reserve the offline graph staging file.');
            }
            fclose($reservation);
        }

        $sqlite = new PDO('sqlite:'.$workPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sqlite->exec('PRAGMA foreign_keys=ON');
        $sqlite->exec('PRAGMA journal_mode=DELETE');
        $sqlite->exec('PRAGMA synchronous=FULL');
        $sqlite->exec('PRAGMA temp_store=FILE');
        $sqlite->exec('PRAGMA cache_size=-8192');
        $this->initialize($sqlite, $sourceIdentity, $resume);

        if ($inspectCheckpoint) {
            $state = (string) $this->meta($sqlite, 'state');

            return ['state' => $state, 'artifact_ready' => $state === 'COMPLETE'];
        }

        $state = $this->meta($sqlite, 'state');
        if (! in_array($state, [
            'WAY_INDEX_COMPLETE', 'SOURCE_PASS_PENDING_PRODUCER', 'RESOLVING_REFERENCES',
            'RESOLUTION_PENDING_PRODUCER', 'REFERENCE_RESOLUTION_INCOMPLETE',
            'RESOLUTION_COMPLETE', 'SOURCE_COMPLETE', 'GRAPH_BUILDING', 'COMPLETE',
        ], true)) {
            $this->resetSource($sqlite, $sourceIdentity);
            $this->ingest($sqlite, $inputPath, $sourceIdentity, $awaitProducerExit);
            $state = $this->meta($sqlite, 'state');
        }
        $checkpoints = new OfflinePedestrianGraphCheckpoint;
        if (in_array($state, ['SOURCE_PASS_PENDING_PRODUCER', 'RESOLUTION_PENDING_PRODUCER'], true)) {
            if (! $producerExitConfirmed) {
                if (! ($awaitProducerExit && $stopAfterSource)) {
                    throw new InvalidArgumentException('The producer has not reported a clean exit; the offline graph remains unpublished.');
                }
            } else {
                $sqlite->beginTransaction();
                if ($state === 'SOURCE_PASS_PENDING_PRODUCER') {
                    $this->setMeta($sqlite, 'source_pass_producer_exit', 'PASS');
                    $this->setMeta($sqlite, 'state', 'WAY_INDEX_COMPLETE');
                    $checkpoints->record($sqlite, $sourceIdentity, 'way_index');
                } else {
                    $this->setMeta($sqlite, 'resolution_producer_exit', 'PASS');
                    $this->setMeta($sqlite, 'references_complete', true);
                    $this->setMeta($sqlite, 'input_complete', true);
                    $this->setMeta($sqlite, 'state', 'SOURCE_COMPLETE');
                    $checkpoints->record($sqlite, $sourceIdentity, 'reference_resolution');
                }
                $sqlite->commit();
            }
        } elseif ($producerExitConfirmed) {
            throw new InvalidArgumentException('Producer-exit confirmation does not match a pending producer checkpoint.');
        }

        if ($this->meta($sqlite, 'state') === 'RESOLUTION_COMPLETE') {
            $sqlite->beginTransaction();
            $this->setMeta($sqlite, 'references_complete', true);
            $this->setMeta($sqlite, 'input_complete', true);
            $this->setMeta($sqlite, 'state', 'SOURCE_COMPLETE');
            $checkpoints->record($sqlite, $sourceIdentity, 'reference_resolution');
            $sqlite->commit();
        }

        if ($this->meta($sqlite, 'state') === 'GRAPH_BUILDING') {
            // Graph writes are deterministic and are rebuilt from the committed source index.
            $this->setMeta($sqlite, 'state', 'SOURCE_COMPLETE');
        }

        if ($resolveRequiredNodes) {
            if (! in_array($this->meta($sqlite, 'state'), [
                'WAY_INDEX_COMPLETE', 'RESOLVING_REFERENCES', 'RESOLUTION_PENDING_PRODUCER', 'REFERENCE_RESOLUTION_INCOMPLETE',
            ], true)) {
                throw new InvalidArgumentException('Referenced-node resolution requires a completed, producer-verified way index.');
            }
            $this->resolveRequiredNodes(
                $sqlite,
                $inputPath,
                $sourceIdentity,
                $inputPath === '-' && ! $resolveFullSource,
                $awaitProducerExit,
            );
            $state = $this->meta($sqlite, 'state');
            if ($state === 'RESOLUTION_COMPLETE') {
                $sqlite->beginTransaction();
                $this->setMeta($sqlite, 'references_complete', true);
                $this->setMeta($sqlite, 'input_complete', true);
                $this->setMeta($sqlite, 'state', 'SOURCE_COMPLETE');
                $checkpoints->record($sqlite, $sourceIdentity, 'reference_resolution');
                $sqlite->commit();
                $state = 'SOURCE_COMPLETE';
            }
        } elseif ($this->meta($sqlite, 'state') === 'WAY_INDEX_COMPLETE' && $inputPath !== '-') {
            // A regular XML file is replayable. Resolve its referenced nodes directly;
            // streamed PBF input uses the explicit second Osmium pass in the wrapper.
            $this->resolveRequiredNodes($sqlite, $inputPath, $sourceIdentity, false, false);
            $sqlite->beginTransaction();
            $this->setMeta($sqlite, 'references_complete', true);
            $this->setMeta($sqlite, 'input_complete', true);
            $this->setMeta($sqlite, 'state', 'SOURCE_COMPLETE');
            $checkpoints->record($sqlite, $sourceIdentity, 'reference_resolution');
            $sqlite->commit();
            $state = 'SOURCE_COMPLETE';
        }

        if ($exportRequiredNodeIds !== null) {
            if ($this->meta($sqlite, 'state') !== 'WAY_INDEX_COMPLETE') {
                throw new InvalidArgumentException('Required-node IDs can be exported only from a producer-verified way index.');
            }
            $this->exportRequiredNodeIds($sqlite, $exportRequiredNodeIds);
        }

        if ($stopAfterSource) {
            $state = (string) $this->meta($sqlite, 'state');

            return ['state' => $state, 'artifact_ready' => false];
        }
        if ($this->meta($sqlite, 'state') !== 'COMPLETE') {
            if ($this->meta($sqlite, 'state') !== 'SOURCE_COMPLETE') {
                throw new InvalidArgumentException('Source ways and referenced nodes are not both complete; graph generation is blocked.');
            }
            $this->buildGraph($sqlite, $sourceIdentity);
        }

        $sqlite = null;
        $validation = (new OfflinePedestrianGraphValidator)->validate($workPath, $sourcePath, $sourceIdentity['sha256']);
        $this->publishNoClobber($workPath, $outputPath);

        return $validation;
    }

    /** @param array<string, mixed> $source */
    private function verifySource(string $sourcePath, array $source): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidArgumentException('A readable local source PBF is required for provenance verification.');
        }
        $url = $source['url'] ?? null;
        $expected = strtolower(trim((string) ($source['sha256'] ?? '')));
        $version = trim((string) ($source['osmium_version'] ?? ''));
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || basename(rawurldecode((string) parse_url($url, PHP_URL_PATH))) !== basename($sourcePath)) {
            throw new InvalidArgumentException('Source URL must be HTTP(S) and its filename must match the local PBF.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $expected) || preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1 || strlen($version) > 80) {
            throw new InvalidArgumentException('A valid expected SHA-256 and bounded Osmium version are required.');
        }
        $actual = hash_file('sha256', $sourcePath);
        if (! is_string($actual) || ! hash_equals($expected, strtolower($actual))) {
            throw new InvalidArgumentException('Local source PBF does not match the expected SHA-256 checksum.');
        }
        $timestamp = $source['timestamp'] ?? null;
        if ($timestamp !== null) {
            try {
                $timestamp = (new DateTimeImmutable((string) $timestamp))->format(DATE_ATOM);
            } catch (\Throwable) {
                throw new InvalidArgumentException('OSM snapshot timestamp is malformed.');
            }
        }

        return [
            'url' => $url,
            'file' => basename($sourcePath),
            'sha256' => strtolower($actual),
            'md5' => hash_file('md5', $sourcePath),
            'timestamp' => $timestamp,
            'osmium_version' => $version,
            'bounds' => config('biyahe.pedestrian.coverage'),
        ];
    }

    private function initialize(PDO $sqlite, array $source, bool $resume): void
    {
        $sqlite->exec('CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $exists = $this->meta($sqlite, 'format');
        if ($exists !== null) {
            if (! $resume) {
                throw new InvalidArgumentException('Existing staging file is not a resumable Biyahe graph artifact.');
            }
            $this->createResumeTables($sqlite);
            (new OfflinePedestrianGraphCheckpoint)->upgradeOrVerify($sqlite, $source);

            return;
        }
        if ($resume) {
            throw new InvalidArgumentException('Cannot resume a staging file without a Biyahe checkpoint.');
        }

        $sqlite->exec('CREATE TABLE source_nodes (id INTEGER PRIMARY KEY, latitude REAL NOT NULL, longitude REAL NOT NULL, in_scope INTEGER NOT NULL, walkable INTEGER NOT NULL, barrier TEXT NULL, crossing TEXT NULL)');
        $sqlite->exec('CREATE TABLE seen_way_ids (id INTEGER PRIMARY KEY)');
        $sqlite->exec('CREATE TABLE seen_relation_ids (id INTEGER PRIMARY KEY)');
        $sqlite->exec('CREATE TABLE source_ways (id INTEGER PRIMARY KEY, tags TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE pending_ways (id INTEGER PRIMARY KEY, tags TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE pending_way_refs (way_id INTEGER NOT NULL, sequence_index INTEGER NOT NULL, node_id INTEGER NOT NULL, PRIMARY KEY (way_id, sequence_index))');
        $sqlite->exec('CREATE TABLE required_nodes (id INTEGER PRIMARY KEY)');
        $sqlite->exec('CREATE TABLE resolved_node_ids (id INTEGER PRIMARY KEY)');
        $sqlite->exec('CREATE TABLE way_refs (way_id INTEGER NOT NULL, sequence_index INTEGER NOT NULL, node_id INTEGER NOT NULL, PRIMARY KEY (way_id, sequence_index))');
        $sqlite->exec('CREATE INDEX way_refs_node_idx ON way_refs (node_id)');
        $sqlite->exec('CREATE TABLE source_restrictions (relation_id INTEGER PRIMARY KEY, tags TEXT NOT NULL, member_count INTEGER NOT NULL, members_sha256 TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE source_restriction_members (relation_id INTEGER NOT NULL, sequence_index INTEGER NOT NULL, member_type TEXT NOT NULL, member_ref INTEGER NOT NULL, role TEXT NOT NULL, PRIMARY KEY (relation_id, sequence_index), FOREIGN KEY (relation_id) REFERENCES source_restrictions(relation_id))');
        $sqlite->exec('CREATE TABLE graph_nodes (osm_node_id INTEGER PRIMARY KEY, latitude REAL NOT NULL, longitude REAL NOT NULL, in_scope INTEGER NOT NULL, walkable INTEGER NOT NULL, barrier TEXT NULL, crossing TEXT NULL)');
        $sqlite->exec('CREATE TABLE graph_edges (osm_way_id INTEGER NOT NULL, segment_index INTEGER NOT NULL, from_node_id INTEGER NOT NULL, to_node_id INTEGER NOT NULL, bidirectional INTEGER NOT NULL, length_meters REAL NOT NULL, highway TEXT NOT NULL, properties TEXT NOT NULL, geometry_wkt TEXT NOT NULL, PRIMARY KEY (osm_way_id, segment_index), FOREIGN KEY (from_node_id) REFERENCES graph_nodes(osm_node_id), FOREIGN KEY (to_node_id) REFERENCES graph_nodes(osm_node_id))');
        $sqlite->exec('CREATE TABLE phase_checkpoints (phase TEXT PRIMARY KEY, source_sha256 TEXT NOT NULL, source_snapshot TEXT NULL, schema_version INTEGER NOT NULL, phase_identity TEXT NOT NULL, completion_state TEXT NOT NULL, record_counts TEXT NOT NULL, output_fingerprint TEXT NOT NULL)');
        foreach ([
            'format' => self::FORMAT, 'schema_version' => self::SCHEMA_VERSION,
            'state' => 'INGESTING', 'input_complete' => false,
            'way_index_complete' => false, 'references_complete' => false,
            'routing_complete' => false,
            'restriction_inventory_complete' => false,
            'restriction_inventory_policy_version' => OfflinePedestrianRestrictionAudit::INVENTORY_VERSION,
        ] as $key => $value) {
            $this->setMeta($sqlite, $key, $value);
        }
        foreach ($source as $key => $value) {
            $this->setMeta($sqlite, 'source_identity_'.$key, $value);
            if ($key !== 'timestamp' || $value !== null) {
                $this->setMeta($sqlite, 'source_'.$key, $value);
            }
        }
        $this->setMeta($sqlite, 'crs', 'EPSG:4326');
        $this->setMeta($sqlite, 'license', 'ODbL-1.0');
        $this->setMeta($sqlite, 'attribution', '© OpenStreetMap contributors');
        $this->setMeta($sqlite, 'extract_strategy', 'offline_scope_first_sqlite_v3');
    }

    private function createResumeTables(PDO $sqlite): void
    {
        $sqlite->exec('CREATE TABLE IF NOT EXISTS pending_ways (id INTEGER PRIMARY KEY, tags TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE IF NOT EXISTS pending_way_refs (way_id INTEGER NOT NULL, sequence_index INTEGER NOT NULL, node_id INTEGER NOT NULL, PRIMARY KEY (way_id, sequence_index))');
        $sqlite->exec('CREATE TABLE IF NOT EXISTS source_restrictions (relation_id INTEGER PRIMARY KEY, tags TEXT NOT NULL, member_count INTEGER NOT NULL, members_sha256 TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE IF NOT EXISTS source_restriction_members (relation_id INTEGER NOT NULL, sequence_index INTEGER NOT NULL, member_type TEXT NOT NULL, member_ref INTEGER NOT NULL, role TEXT NOT NULL, PRIMARY KEY (relation_id, sequence_index), FOREIGN KEY (relation_id) REFERENCES source_restrictions(relation_id))');
    }

    private function resetSource(PDO $sqlite, array $source): void
    {
        $sqlite->beginTransaction();
        foreach (['source_nodes', 'seen_way_ids', 'seen_relation_ids', 'source_ways', 'pending_ways', 'pending_way_refs', 'required_nodes', 'resolved_node_ids', 'way_refs', 'source_restriction_members', 'source_restrictions', 'graph_nodes', 'graph_edges'] as $table) {
            $sqlite->exec('DELETE FROM '.$table);
        }
        $sqlite->exec('DELETE FROM phase_checkpoints');
        foreach (['source_nodes', 'indexed_source_nodes', 'source_ways', 'candidate_ways', 'relations', 'relation_restrictions', 'required_nodes', 'resolved_nodes', 'scope_nodes', 'graph_nodes', 'graph_edges', 'component_count', 'graph_content_sha256'] as $key) {
            $sqlite->exec('DELETE FROM metadata WHERE key='.$sqlite->quote($key));
        }
        if (($source['timestamp'] ?? null) === null) {
            $sqlite->exec("DELETE FROM metadata WHERE key='source_timestamp'");
        }
        $this->setMeta($sqlite, 'state', 'INGESTING');
        $this->setMeta($sqlite, 'input_complete', false);
        $this->setMeta($sqlite, 'way_index_complete', false);
        $this->setMeta($sqlite, 'references_complete', false);
        $this->setMeta($sqlite, 'restriction_inventory_complete', false);
        $this->setMeta($sqlite, 'restriction_inventory_policy_version', OfflinePedestrianRestrictionAudit::INVENTORY_VERSION);
        foreach ($source as $key => $value) {
            $this->setMeta($sqlite, 'source_identity_'.$key, $value);
            if ($key !== 'timestamp' || $value !== null) {
                $this->setMeta($sqlite, 'source_'.$key, $value);
            }
        }
        $sqlite->commit();
    }

    private function ingest(PDO $sqlite, string $inputPath, array $source, bool $awaitProducerExit): void
    {
        $previousLibxmlSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;
        $opened = $inputPath === '-'
            ? $reader->open('php://stdin', null, LIBXML_NONET | LIBXML_COMPACT)
            : $reader->open($inputPath, null, LIBXML_NONET | LIBXML_COMPACT);
        if (! $opened) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);
            throw new InvalidArgumentException('Could not open the source XML stream.');
        }
        $scope = $source['bounds'];
        $nodeInsert = $sqlite->prepare('INSERT INTO source_nodes (id, latitude, longitude, in_scope, walkable, barrier, crossing) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $seenWay = $sqlite->prepare('INSERT INTO seen_way_ids (id) VALUES (?)');
        $seenRelation = $sqlite->prepare('INSERT INTO seen_relation_ids (id) VALUES (?)');
        $pendingWayInsert = $sqlite->prepare('INSERT INTO pending_ways (id, tags) VALUES (?, ?)');
        $pendingRefInsert = $sqlite->prepare('INSERT INTO pending_way_refs (way_id, sequence_index, node_id) VALUES (?, ?, ?)');
        $nodeCount = $wayCount = $relationCount = $candidateWays = $scopeNodes = $requiredCount = $restrictedRelations = 0;
        $rootSeen = $rootClosed = false;
        $timestamp = $source['timestamp'];
        $transaction = false;

        try {
            $sqlite->beginTransaction();
            $transaction = true;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'osm' && $reader->depth === 0) {
                    $rootClosed = true;

                    continue;
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if (! $rootSeen) {
                    if ($reader->name !== 'osm') {
                        throw new InvalidArgumentException('Input does not contain an OSM XML root.');
                    }
                    $rootSeen = true;
                    $streamTimestamp = $reader->getAttribute('timestamp');
                    if (is_string($streamTimestamp) && $streamTimestamp !== '') {
                        try {
                            $streamTimestamp = (new DateTimeImmutable($streamTimestamp))->format(DATE_ATOM);
                        } catch (\Throwable) {
                            throw new InvalidArgumentException('OSM XML snapshot timestamp is malformed.');
                        }
                        if ($timestamp !== null && $timestamp !== $streamTimestamp) {
                            throw new InvalidArgumentException('XML snapshot timestamp does not match the declared source timestamp.');
                        }
                        $timestamp ??= $streamTimestamp;
                    }

                    continue;
                }

                if ($reader->name === 'node') {
                    $node = $this->readNode($reader);
                    $inside = PedestrianOsmPolicy::insideScope($node['lat'], $node['lon'], $scope);
                    if ($inside) {
                        $tags = PedestrianOsmPolicy::tags($node['tags']);
                        try {
                            $nodeInsert->execute([
                                $node['id'], $node['lat'], $node['lon'], 1,
                                PedestrianOsmPolicy::blockedNode($tags) ? 0 : 1,
                                $tags['barrier'] ?? null,
                                $tags['crossing'] ?? (($tags['highway'] ?? null) === 'crossing' ? 'MAPPED' : null),
                            ]);
                        } catch (PDOException $exception) {
                            throw new InvalidArgumentException('Duplicate OSM node ID in source stream (in-scope index).', 0, $exception);
                        }
                    }
                    $nodeCount++;
                    if ($nodeCount > 10_000_000) {
                        throw new InvalidArgumentException('OSM source exceeds the supported node count.');
                    }
                    $scopeNodes += $inside ? 1 : 0;
                    if ($scopeNodes > max(1, min(500_000, (int) config('biyahe.pedestrian.maximum_offline_scope_nodes', 200_000)))) {
                        throw new InvalidArgumentException('OSM source exceeds the configured in-scope node limit.');
                    }
                } elseif ($reader->name === 'way') {
                    $way = $this->readWay($reader);
                    try {
                        $seenWay->execute([$way['id']]);
                    } catch (PDOException $exception) {
                        throw new InvalidArgumentException('Duplicate OSM way ID in source stream.', 0, $exception);
                    }
                    $wayCount++;
                    if ($wayCount > 10_000_000) {
                        throw new InvalidArgumentException('OSM source exceeds the supported way count.');
                    }
                    $wayTags = PedestrianOsmPolicy::tags($way['tags']);
                    if (! PedestrianOsmPolicy::walkableWay($wayTags)) {
                        continue;
                    }
                    $pendingWayInsert->execute([$way['id'], json_encode($wayTags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
                    foreach ($way['refs'] as $index => $nodeId) {
                        $pendingRefInsert->execute([$way['id'], $index, $nodeId]);
                    }
                } elseif ($reader->name === 'relation') {
                    $relation = $this->readRelation($reader);
                    try {
                        $seenRelation->execute([$relation['id']]);
                    } catch (PDOException $exception) {
                        throw new InvalidArgumentException('Duplicate OSM relation ID in source stream.', 0, $exception);
                    }
                    $relationCount++;
                    if ($relationCount > 10_000_000) {
                        throw new InvalidArgumentException('OSM source exceeds the supported relation count.');
                    }
                    if (self::isTurnRestrictionRelation($relation['tags'])) {
                        $restrictedRelations++;
                    }
                }

                if (($nodeCount + $wayCount + $relationCount) % self::COMMIT_EVERY_ELEMENTS === 0) {
                    $this->writeProgress($sqlite, $nodeCount, $wayCount, $relationCount, $candidateWays, $scopeNodes, $requiredCount);
                    $sqlite->commit();
                    $transaction = false;
                    $this->recordProgress('phase=way-index source_nodes='.$nodeCount.' source_ways='.$wayCount.' relations='.$relationCount.' candidate_ways='.$candidateWays.' scope_nodes='.$scopeNodes.' required_nodes='.$requiredCount);
                    $sqlite->beginTransaction();
                    $transaction = true;
                }
            }
            if (! $rootSeen || ! $rootClosed || $nodeCount === 0 || $wayCount === 0 || libxml_get_errors() !== []) {
                throw new InvalidArgumentException('OSM XML stream is empty, malformed, or incomplete.');
            }
            if ($timestamp === null) {
                throw new InvalidArgumentException('An OSM snapshot timestamp is required.');
            }
            $this->selectCandidateWays($sqlite, $candidateWays, $requiredCount);
            if ($scopeNodes === 0 || $candidateWays === 0) {
                throw new InvalidArgumentException('OSM stream contains no pedestrian candidate segments inside the configured bounds.');
            }
            $this->writeProgress($sqlite, $nodeCount, $wayCount, $relationCount, $candidateWays, $scopeNodes, $requiredCount);
            $this->setMeta($sqlite, 'source_timestamp', $timestamp);
            $this->setMeta($sqlite, 'relation_restrictions', $restrictedRelations);
            $this->setMeta($sqlite, 'way_index_complete', true);
            $this->setMeta($sqlite, 'source_pass_producer_exit', $awaitProducerExit ? 'PENDING' : 'PASS');
            $this->setMeta($sqlite, 'state', $awaitProducerExit ? 'SOURCE_PASS_PENDING_PRODUCER' : 'WAY_INDEX_COMPLETE');
            if (! $awaitProducerExit) {
                (new OfflinePedestrianGraphCheckpoint)->record($sqlite, $source, 'way_index');
            }
            $sqlite->commit();
            $transaction = false;
            $this->recordProgress('phase=way-index source_nodes='.$nodeCount.' source_ways='.$wayCount.' relations='.$relationCount.' candidate_ways='.$candidateWays.' scope_nodes='.$scopeNodes.' required_nodes='.$requiredCount);
        } catch (\Throwable $exception) {
            if ($transaction && $sqlite->inTransaction()) {
                $sqlite->rollBack();
            }
            $this->setMeta($sqlite, 'state', 'SOURCE_INCOMPLETE');
            $this->setMeta($sqlite, 'input_complete', false);
            throw $exception;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);
        }
    }

    private function resetReferenceResolution(PDO $sqlite, bool $resetRestrictionDependencies = false): void
    {
        $sqlite->beginTransaction();
        $sqlite->exec('DELETE FROM resolved_node_ids');
        $sqlite->exec('DELETE FROM source_nodes WHERE in_scope=0');
        if ($resetRestrictionDependencies) {
            $sqlite->exec('DELETE FROM source_restriction_members');
            $sqlite->exec('DELETE FROM source_restrictions');
        }
        $sqlite->exec("DELETE FROM phase_checkpoints WHERE phase IN ('reference_resolution', 'graph_build')");
        foreach (['resolved_nodes', 'indexed_source_nodes'] as $key) {
            $sqlite->exec('DELETE FROM metadata WHERE key='.$sqlite->quote($key));
        }
        $this->setMeta($sqlite, 'restriction_inventory_complete', false);
        $this->setMeta($sqlite, 'references_complete', false);
        $this->setMeta($sqlite, 'input_complete', false);
        $this->setMeta($sqlite, 'state', 'WAY_INDEX_COMPLETE');
        $sqlite->commit();
    }

    private function resolveRequiredNodes(PDO $sqlite, string $inputPath, array $source, bool $strictNodesOnly, bool $awaitProducerExit): void
    {
        $captureRestrictionDependencies = ! $strictNodesOnly;
        $this->resetReferenceResolution($sqlite, $captureRestrictionDependencies);
        if ($captureRestrictionDependencies) {
            $sqlite->exec('CREATE TEMP TABLE IF NOT EXISTS restriction_member_spool (relation_id INTEGER NOT NULL, sequence_index INTEGER NOT NULL, member_type TEXT NOT NULL, member_ref INTEGER NOT NULL, role TEXT NOT NULL, PRIMARY KEY (relation_id, sequence_index))');
            $sqlite->exec('DELETE FROM temp.restriction_member_spool');
        }
        $this->setMeta($sqlite, 'state', 'RESOLVING_REFERENCES');
        $previousLibxmlSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;
        $opened = $inputPath === '-'
            ? $reader->open('php://stdin', null, LIBXML_NONET | LIBXML_COMPACT)
            : $reader->open($inputPath, null, LIBXML_NONET | LIBXML_COMPACT);
        if (! $opened) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);
            throw new InvalidArgumentException('Could not open the referenced-node XML stream.');
        }

        $scope = $source['bounds'];
        $required = $sqlite->prepare('SELECT 1 FROM required_nodes WHERE id=?');
        $resolvedInsert = $sqlite->prepare('INSERT INTO resolved_node_ids (id) VALUES (?)');
        $existingNode = $sqlite->prepare('SELECT latitude, longitude, in_scope, walkable, barrier, crossing FROM source_nodes WHERE id=?');
        $insertNode = $sqlite->prepare('INSERT INTO source_nodes (id, latitude, longitude, in_scope, walkable, barrier, crossing) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $resolvedCount = 0;
        $rootSeen = $rootClosed = false;
        $transaction = false;

        try {
            $sqlite->beginTransaction();
            $transaction = true;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'osm' && $reader->depth === 0) {
                    $rootClosed = true;

                    continue;
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if (! $rootSeen) {
                    if ($reader->name !== 'osm') {
                        throw new InvalidArgumentException('Referenced-node input does not contain an OSM XML root.');
                    }
                    $rootSeen = true;
                    $timestamp = $reader->getAttribute('timestamp');
                    if (is_string($timestamp) && $timestamp !== '' && $source['timestamp'] !== null) {
                        try {
                            $normalized = (new DateTimeImmutable($timestamp))->format(DATE_ATOM);
                        } catch (\Throwable) {
                            throw new InvalidArgumentException('Referenced-node XML snapshot timestamp is malformed.');
                        }
                        if ($normalized !== $source['timestamp']) {
                            throw new InvalidArgumentException('Referenced-node XML snapshot does not match the declared source timestamp.');
                        }
                    }

                    continue;
                }

                if ($reader->name === 'relation') {
                    if ($captureRestrictionDependencies) {
                        $this->readRestrictionRelation($reader, $sqlite);
                    } elseif ($strictNodesOnly) {
                        throw new InvalidArgumentException('The Osmium reference pass must contain nodes only.');
                    }

                    continue;
                }
                if ($reader->name !== 'node') {
                    if ($strictNodesOnly && $reader->name === 'way') {
                        throw new InvalidArgumentException('The Osmium reference pass must contain nodes only.');
                    }

                    continue;
                }

                $id = self::positiveId($reader->getAttribute('id'));
                $required->execute([$id]);
                $isRequired = $required->fetchColumn() !== false;
                $required->closeCursor();
                if (! $isRequired) {
                    if ($strictNodesOnly) {
                        throw new InvalidArgumentException('Osmium reference pass returned an unrequested node.');
                    }

                    continue;
                }

                $node = $this->readNode($reader);
                try {
                    $resolvedInsert->execute([$node['id']]);
                } catch (PDOException $exception) {
                    throw new InvalidArgumentException('Duplicate required OSM node ID in reference stream.', 0, $exception);
                }
                $inside = PedestrianOsmPolicy::insideScope($node['lat'], $node['lon'], $scope);
                $tags = PedestrianOsmPolicy::tags($node['tags']);
                $walkable = PedestrianOsmPolicy::blockedNode($tags) ? 0 : 1;
                $barrier = $tags['barrier'] ?? null;
                $crossing = $tags['crossing'] ?? (($tags['highway'] ?? null) === 'crossing' ? 'MAPPED' : null);
                $existingNode->execute([$node['id']]);
                $existing = $existingNode->fetch(PDO::FETCH_ASSOC);
                $existingNode->closeCursor();
                if ($existing !== false) {
                    if ((float) $existing['latitude'] !== $node['lat'] || (float) $existing['longitude'] !== $node['lon']
                        || (int) $existing['in_scope'] !== (int) $inside || (int) $existing['walkable'] !== $walkable
                        || $existing['barrier'] !== $barrier || $existing['crossing'] !== $crossing) {
                        throw new InvalidArgumentException('Referenced node data differs between source passes.');
                    }
                } else {
                    $insertNode->execute([$node['id'], $node['lat'], $node['lon'], $inside ? 1 : 0, $walkable, $barrier, $crossing]);
                }
                $resolvedCount++;
                if ($resolvedCount > max(1, (int) config('biyahe.pedestrian.maximum_input_nodes', 100_000))) {
                    throw new InvalidArgumentException('Resolved candidate references exceed the configured node limit.');
                }
                if ($resolvedCount % self::COMMIT_EVERY_ELEMENTS === 0) {
                    $sqlite->commit();
                    $transaction = false;
                    $sqlite->beginTransaction();
                    $transaction = true;
                    $this->setMeta($sqlite, 'resolved_nodes', $resolvedCount);
                    $this->setMeta($sqlite, 'indexed_source_nodes', (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes')->fetchColumn());
                    $sqlite->commit();
                    $transaction = false;
                    $this->recordProgress('phase=references resolved_nodes='.$resolvedCount.' required_nodes='.(int) $sqlite->query('SELECT COUNT(*) FROM required_nodes')->fetchColumn());
                    $sqlite->beginTransaction();
                    $transaction = true;
                }
            }

            $expected = (int) $sqlite->query('SELECT COUNT(*) FROM required_nodes')->fetchColumn();
            $resolved = (int) $sqlite->query('SELECT COUNT(*) FROM resolved_node_ids')->fetchColumn();
            if (! $rootSeen || ! $rootClosed || libxml_get_errors() !== []) {
                throw new InvalidArgumentException('Referenced-node XML stream is malformed or incomplete.');
            }
            if ($resolved !== $expected) {
                throw new InvalidArgumentException('A candidate way references a node missing from the source stream.');
            }
            if ($captureRestrictionDependencies) {
                $capturedRestrictions = (int) $sqlite->query('SELECT COUNT(*) FROM source_restrictions')->fetchColumn();
                if ($capturedRestrictions !== (int) $this->meta($sqlite, 'relation_restrictions')) {
                    throw new InvalidArgumentException('The complete source pass did not preserve every restriction relation and member.');
                }
                $this->setMeta($sqlite, 'restriction_inventory_complete', true);
                $this->setMeta($sqlite, 'restriction_inventory_policy_version', OfflinePedestrianRestrictionAudit::INVENTORY_VERSION);
            } elseif ((int) $this->meta($sqlite, 'relation_restrictions') === 0) {
                // The complete way-index pass also proves there were no tagged
                // restriction relations when a nodes-only resolution pass is used.
                $this->setMeta($sqlite, 'restriction_inventory_complete', true);
                $this->setMeta($sqlite, 'restriction_inventory_policy_version', OfflinePedestrianRestrictionAudit::INVENTORY_VERSION);
            } else {
                $this->setMeta($sqlite, 'restriction_inventory_complete', false);
            }
            $this->setMeta($sqlite, 'resolved_nodes', $resolved);
            $this->setMeta($sqlite, 'indexed_source_nodes', (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes')->fetchColumn());
            $this->setMeta($sqlite, 'resolution_xml_complete', true);
            $this->setMeta($sqlite, 'resolution_producer_exit', $awaitProducerExit ? 'PENDING' : 'PASS');
            $this->setMeta($sqlite, 'references_complete', ! $awaitProducerExit);
            $this->setMeta($sqlite, 'input_complete', ! $awaitProducerExit);
            $this->setMeta($sqlite, 'state', $awaitProducerExit ? 'RESOLUTION_PENDING_PRODUCER' : 'RESOLUTION_COMPLETE');
            $sqlite->commit();
            $transaction = false;
            $this->recordProgress('phase=references resolved_nodes='.$resolved.' required_nodes='.$expected);
        } catch (\Throwable $exception) {
            if ($transaction && $sqlite->inTransaction()) {
                $sqlite->rollBack();
            }
            $this->setMeta($sqlite, 'references_complete', false);
            $this->setMeta($sqlite, 'input_complete', false);
            $this->setMeta($sqlite, 'state', 'REFERENCE_RESOLUTION_INCOMPLETE');
            throw $exception;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);
        }
    }

    private function exportRequiredNodeIds(PDO $sqlite, string $path): void
    {
        if ($path === '' || file_exists($path) || ! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            throw new InvalidArgumentException('Required-node ID output must be a new file in an existing writable directory.');
        }
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Could not reserve the required-node ID file.');
        }
        try {
            foreach ($sqlite->query('SELECT id FROM required_nodes ORDER BY id') as $row) {
                if (fwrite($stream, 'n'.(int) $row['id']."\n") === false) {
                    throw new RuntimeException('Could not write the complete required-node ID file.');
                }
            }
            if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                throw new RuntimeException('Could not flush the required-node ID file.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function buildGraph(PDO $sqlite, array $sourceIdentity): void
    {
        $sqlite->beginTransaction();
        $sqlite->exec('DELETE FROM graph_edges');
        $sqlite->exec('DELETE FROM graph_nodes');
        $sqlite->exec("DELETE FROM phase_checkpoints WHERE phase='graph_build'");
        $this->setMeta($sqlite, 'state', 'GRAPH_BUILDING');
        $sqlite->commit();
        $sqlite->beginTransaction();

        $scope = $this->meta($sqlite, 'source_bounds');
        $insertNode = $sqlite->prepare('INSERT OR IGNORE INTO graph_nodes (osm_node_id, latitude, longitude, in_scope, walkable, barrier, crossing) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insertEdge = $sqlite->prepare('INSERT INTO graph_edges (osm_way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, highway, properties, geometry_wkt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $wayQuery = $sqlite->query('SELECT id, tags FROM source_ways ORDER BY id');
        $edgeCount = $acceptedWays = $restrictedWays = 0;
        $transactionEdges = 0;

        while ($way = $wayQuery->fetch(PDO::FETCH_ASSOC)) {
            $tags = PedestrianOsmPolicy::tags(json_decode((string) $way['tags'], true, 32, JSON_THROW_ON_ERROR));
            if (PedestrianOsmPolicy::explicitlyRestrictedWay($tags)) {
                $restrictedWays++;
            }
            if (! PedestrianOsmPolicy::walkableWay($tags)) {
                continue;
            }
            [$forward, $backward] = PedestrianOsmPolicy::directions($tags);
            if (! $forward && ! $backward) {
                continue;
            }
            $references = $sqlite->prepare('SELECT refs.sequence_index, refs.node_id, nodes.latitude, nodes.longitude, nodes.in_scope, nodes.walkable, nodes.barrier, nodes.crossing FROM way_refs AS refs LEFT JOIN source_nodes AS nodes ON nodes.id = refs.node_id WHERE refs.way_id = ? ORDER BY refs.sequence_index');
            $references->execute([(int) $way['id']]);
            $previous = null;
            $wayAdded = false;
            $highway = (string) ($tags['highway'] ?? '');
            $properties = array_intersect_key($tags, array_flip([
                'surface', 'smoothness', 'bridge', 'tunnel', 'layer', 'sidewalk', 'footway', 'crossing',
                'crossing:markings', 'crossing:signals', 'foot', 'access', 'oneway:foot', 'foot:forward', 'foot:backward',
            ]));
            while ($current = $references->fetch(PDO::FETCH_ASSOC)) {
                if ($current['latitude'] === null) {
                    throw new InvalidArgumentException('A candidate pedestrian way references a node missing from the offline source index.');
                }
                if ($previous !== null && (bool) $previous['in_scope'] && (bool) $current['in_scope']
                    && (bool) $previous['walkable'] && (bool) $current['walkable']) {
                    $length = Geo::distanceMeters((float) $previous['latitude'], (float) $previous['longitude'], (float) $current['latitude'], (float) $current['longitude']);
                    if (is_finite($length) && $length >= 0.1 && $length <= 10_000) {
                        $segment = (int) $current['sequence_index'] - 1;
                        $from = $forward ? $previous : $current;
                        $to = $forward ? $current : $previous;
                        $bidirectional = $forward && $backward;
                        $insertNode->execute([(int) $from['node_id'], $from['latitude'], $from['longitude'], 1, 1, $from['barrier'], $from['crossing']]);
                        $insertNode->execute([(int) $to['node_id'], $to['latitude'], $to['longitude'], 1, 1, $to['barrier'], $to['crossing']]);
                        $wkt = sprintf('LINESTRING(%.7F %.7F,%.7F %.7F)', (float) $from['longitude'], (float) $from['latitude'], (float) $to['longitude'], (float) $to['latitude']);
                        $insertEdge->execute([
                            (int) $way['id'], $segment, (int) $from['node_id'], (int) $to['node_id'],
                            $bidirectional ? 1 : 0, round($length, 2), $highway,
                            json_encode($properties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $wkt,
                        ]);
                        $edgeCount++;
                        $transactionEdges++;
                        $wayAdded = true;
                        if ($edgeCount > max(1, (int) config('biyahe.pedestrian.maximum_input_edges', 100_000))) {
                            throw new InvalidArgumentException('Offline graph exceeds the configured pedestrian-edge limit.');
                        }
                        if ($transactionEdges >= 5_000) {
                            $sqlite->commit();
                            $sqlite->beginTransaction();
                            $transactionEdges = 0;
                            $this->recordProgress('phase=graph edges='.$edgeCount.' accepted_ways='.$acceptedWays);
                        }
                    }
                }
                $previous = $current;
            }
            if ($wayAdded) {
                $acceptedWays++;
            }
        }
        $sqlite->commit();
        $this->recordProgress('phase=graph edges='.$edgeCount.' accepted_ways='.$acceptedWays);

        $sqlite->beginTransaction();
        $sqlite->exec('INSERT OR IGNORE INTO graph_nodes (osm_node_id, latitude, longitude, in_scope, walkable, barrier, crossing) SELECT id, latitude, longitude, in_scope, walkable, barrier, crossing FROM source_nodes WHERE in_scope=1 AND walkable=0');
        $nodeCount = (int) $sqlite->query('SELECT COUNT(*) FROM graph_nodes')->fetchColumn();
        $edgeCount = (int) $sqlite->query('SELECT COUNT(*) FROM graph_edges')->fetchColumn();
        if ($nodeCount === 0 || $edgeCount === 0) {
            throw new InvalidArgumentException('The offline graph has no usable pedestrian edges.');
        }
        $components = OfflinePedestrianGraphTopology::componentCount($sqlite);
        $contentHash = OfflinePedestrianGraphTopology::contentHash($sqlite);
        $restrictionCounts = (new OfflinePedestrianRestrictionPolicy)->audit($sqlite)['counts'];
        $restrictionRoutingReady = (bool) $this->meta($sqlite, 'restriction_inventory_complete')
            && (int) $restrictionCounts['unsupported_applicable'] === 0
            && (int) $restrictionCounts['ambiguous'] === 0;
        foreach ([
            'state' => 'COMPLETE', 'graph_nodes' => $nodeCount, 'graph_edges' => $edgeCount,
            'accepted_ways' => $acceptedWays, 'restricted_ways' => $restrictedWays,
            'component_count' => $components, 'graph_content_sha256' => $contentHash,
            'routing_complete' => $restrictionRoutingReady,
            'crs' => 'EPSG:4326',
        ] as $key => $value) {
            $this->setMeta($sqlite, $key, $value);
        }
        (new OfflinePedestrianGraphCheckpoint)->record($sqlite, $sourceIdentity, 'graph_build');
        $sqlite->commit();
        $this->recordProgress('phase=graph nodes='.$nodeCount.' edges='.$edgeCount.' components='.$components);
    }

    private function readNode(XMLReader $reader): array
    {
        $id = $this->positiveId($reader->getAttribute('id'));
        $latitude = $reader->getAttribute('lat');
        $longitude = $reader->getAttribute('lon');
        if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
            || (float) $latitude < -90 || (float) $latitude > 90 || (float) $longitude < -180 || (float) $longitude > 180) {
            throw new InvalidArgumentException('OSM nodes require valid WGS84 coordinates.');
        }
        $tags = [];
        $this->readChildren($reader, static function (XMLReader $child) use (&$tags): void {
            if ($child->name === 'tag') {
                $key = $child->getAttribute('k');
                $value = $child->getAttribute('v');
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $tags[$key] = $value;
                }
            }
        });

        return ['id' => $id, 'lat' => (float) $latitude, 'lon' => (float) $longitude, 'tags' => $tags];
    }

    private function readWay(XMLReader $reader): array
    {
        $id = $this->positiveId($reader->getAttribute('id'));
        $references = [];
        $tags = [];
        $this->readChildren($reader, static function (XMLReader $child) use (&$references, &$tags): void {
            if ($child->name === 'nd') {
                if (count($references) >= self::MAX_WAY_REFERENCES) {
                    throw new InvalidArgumentException('OSM way exceeds the bounded reference count.');
                }
                $references[] = self::positiveId($child->getAttribute('ref'));
            } elseif ($child->name === 'tag') {
                $key = $child->getAttribute('k');
                $value = $child->getAttribute('v');
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $tags[$key] = $value;
                }
            }
        });
        if (count($references) < 2) {
            throw new InvalidArgumentException('OSM ways require at least two positive node references.');
        }

        return ['id' => $id, 'refs' => $references, 'tags' => $tags];
    }

    private function readRelation(XMLReader $reader): array
    {
        $id = $this->positiveId($reader->getAttribute('id'));
        $tags = [];
        $this->readChildren($reader, static function (XMLReader $child) use (&$tags): void {
            if ($child->name === 'tag') {
                $key = $child->getAttribute('k');
                $value = $child->getAttribute('v');
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $tags[$key] = strtolower(trim($value));
                }
            }
        });

        return ['id' => $id, 'tags' => $tags];
    }

    private function readRestrictionRelation(XMLReader $reader, PDO $sqlite): void
    {
        $id = self::positiveId($reader->getAttribute('id'));
        $tags = [];
        $sequence = 0;
        $memberInsert = $sqlite->prepare('INSERT INTO temp.restriction_member_spool (relation_id, sequence_index, member_type, member_ref, role) VALUES (?, ?, ?, ?, ?)');
        $spoolClear = $sqlite->prepare('DELETE FROM temp.restriction_member_spool WHERE relation_id=?');
        $this->readChildren($reader, function (XMLReader $child) use (&$tags, &$sequence, $id, $memberInsert): void {
            if ($child->name === 'tag') {
                $key = $child->getAttribute('k');
                $value = $child->getAttribute('v');
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $tags[$key] = strtolower(trim($value));
                }
            } elseif ($child->name === 'member') {
                $type = $child->getAttribute('type');
                $role = $child->getAttribute('role');
                if (! is_string($type) || ! in_array($type, ['node', 'way', 'relation'], true)) {
                    throw new InvalidArgumentException('OSM relation member type is invalid.');
                }
                if (++$sequence > self::MAX_RELATION_MEMBERS) {
                    throw new InvalidArgumentException('OSM relation exceeds the bounded member count.');
                }
                $memberInsert->execute([$id, $sequence - 1, $type, self::positiveId($child->getAttribute('ref')), is_string($role) ? $role : '']);
            }
        });

        if (! self::isTurnRestrictionRelation($tags)) {
            $spoolClear->execute([$id]);

            return;
        }

        $memberRows = $sqlite->query('SELECT sequence_index, member_type, member_ref, role FROM temp.restriction_member_spool WHERE relation_id='.(int) $id.' ORDER BY sequence_index');
        $memberHash = hash_init('sha256');
        $memberCount = 0;
        while ($member = $memberRows->fetch(PDO::FETCH_NUM)) {
            hash_update($memberHash, json_encode($member, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            $memberCount++;
        }
        $membersSha256 = hash_final($memberHash);
        $restrictionInsert = $sqlite->prepare('INSERT INTO source_restrictions (relation_id, tags, member_count, members_sha256) VALUES (?, ?, ?, ?)');
        $restrictionInsert->execute([$id, json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $memberCount, $membersSha256]);
        $sqlite->exec('INSERT INTO source_restriction_members (relation_id, sequence_index, member_type, member_ref, role) SELECT relation_id, sequence_index, member_type, member_ref, role FROM temp.restriction_member_spool WHERE relation_id='.(int) $id);
        $spoolClear->execute([$id]);
    }

    /** @param array<string, string> $tags */
    private static function isTurnRestrictionRelation(array $tags): bool
    {
        if (in_array($tags['type'] ?? null, ['restriction', 'restriction:foot', 'restriction:pedestrian'], true)) {
            return true;
        }

        foreach (array_keys($tags) as $key) {
            if (str_starts_with($key, 'restriction:') || $key === 'restriction') {
                return true;
            }
        }

        return false;
    }

    private function readChildren(XMLReader $reader, callable $consume): void
    {
        if ($reader->isEmptyElement) {
            return;
        }
        $depth = $reader->depth;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                return;
            }
            if ($reader->nodeType === XMLReader::ELEMENT) {
                $consume($reader);
            }
        }
        throw new InvalidArgumentException('OSM XML ended before an element was complete.');
    }

    private static function positiveId(?string $value): int
    {
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('OSM elements and references require positive integer IDs.');
        }

        return (int) $value;
    }

    private function selectCandidateWays(PDO $sqlite, int &$candidateWays, int &$requiredCount): void
    {
        $sqlite->exec('INSERT INTO source_ways (id, tags) SELECT DISTINCT ways.id, ways.tags FROM pending_ways AS ways JOIN pending_way_refs AS first_ref ON first_ref.way_id=ways.id JOIN pending_way_refs AS next_ref ON next_ref.way_id=first_ref.way_id AND next_ref.sequence_index=first_ref.sequence_index+1 JOIN source_nodes AS first_node ON first_node.id=first_ref.node_id AND first_node.in_scope=1 JOIN source_nodes AS next_node ON next_node.id=next_ref.node_id AND next_node.in_scope=1');
        $candidateWays = (int) $sqlite->query('SELECT COUNT(*) FROM source_ways')->fetchColumn();
        if ($candidateWays > max(1, (int) config('biyahe.pedestrian.maximum_input_ways', 25_000))) {
            throw new InvalidArgumentException('OSM source exceeds the configured candidate-way limit.');
        }
        $sqlite->exec('INSERT INTO way_refs (way_id, sequence_index, node_id) SELECT refs.way_id, refs.sequence_index, refs.node_id FROM pending_way_refs AS refs JOIN source_ways AS ways ON ways.id=refs.way_id');
        $sqlite->exec('INSERT INTO required_nodes (id) SELECT DISTINCT refs.node_id FROM way_refs AS refs');
        $requiredCount = (int) $sqlite->query('SELECT COUNT(*) FROM required_nodes')->fetchColumn();
        if ($requiredCount > max(1, (int) config('biyahe.pedestrian.maximum_input_nodes', 100_000))) {
            throw new InvalidArgumentException('Candidate ways exceed the configured referenced-node limit.');
        }
        $sqlite->exec('DELETE FROM pending_way_refs');
        $sqlite->exec('DELETE FROM pending_ways');
    }

    private function writeProgress(PDO $sqlite, int $nodes, int $ways, int $relations, int $candidates, int $scopeNodes, int $requiredNodes): void
    {
        foreach ([
            'source_nodes' => $nodes,
            'indexed_source_nodes' => $scopeNodes,
            'source_ways' => $ways,
            'relations' => $relations,
            'candidate_ways' => $candidates,
            'scope_nodes' => $scopeNodes,
            'required_nodes' => $requiredNodes,
        ] as $key => $value) {
            $this->setMeta($sqlite, $key, $value);
        }
    }

    private function recordProgress(string $message): void
    {
        $path = getenv('BIYAHE_OSM_PROGRESS_FILE');
        if (! is_string($path) || $path === '' || ! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            return;
        }
        @file_put_contents($path, gmdate(DATE_ATOM).' '.$message."\n", FILE_APPEND | LOCK_EX);
    }

    private function setMeta(PDO $sqlite, string $key, mixed $value): void
    {
        $statement = $sqlite->prepare('INSERT INTO metadata (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        $statement->execute([$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }

    private function meta(PDO $sqlite, string $key): mixed
    {
        $statement = $sqlite->prepare('SELECT value FROM metadata WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : json_decode((string) $value, true, 32, JSON_THROW_ON_ERROR);
    }

    private function publishNoClobber(string $workPath, string $outputPath): void
    {
        $reservation = @fopen($outputPath, 'x');
        if ($reservation === false) {
            throw new RuntimeException('Output path already exists or cannot be reserved safely.');
        }
        $owned = fstat($reservation);
        fclose($reservation);
        clearstatcache(true, $outputPath);
        $current = @lstat($outputPath);
        if (! is_array($owned) || ! is_array($current)
            || $owned['dev'] !== $current['dev'] || $owned['ino'] !== $current['ino']) {
            throw new RuntimeException('Output reservation changed before atomic graph publication.');
        }
        if (! rename($workPath, $outputPath)) {
            clearstatcache(true, $outputPath);
            $current = @lstat($outputPath);
            if (is_array($owned) && is_array($current) && $owned['dev'] === $current['dev'] && $owned['ino'] === $current['ino']) {
                @unlink($outputPath);
            }
            throw new RuntimeException('Could not atomically publish the complete offline graph.');
        }
    }
}
