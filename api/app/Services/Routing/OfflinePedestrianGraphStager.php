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
    private const FORMAT = 'biyahe-pedestrian-sqlite-v1';
    private const SCHEMA_VERSION = 1;
    private const MAX_WAY_REFERENCES = 100_000;
    private const COMMIT_EVERY_ELEMENTS = 25_000;

    /**
     * @param array{url: string, sha256: string, timestamp: ?string, osmium_version: string} $source
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

        $state = $this->meta($sqlite, 'state');
        if (! in_array($state, ['SOURCE_COMPLETE', 'SOURCE_COMPLETE_PENDING_PRODUCER', 'GRAPH_BUILDING', 'COMPLETE'], true)) {
            $this->resetSource($sqlite, $sourceIdentity);
            $this->ingest($sqlite, $inputPath, $sourceIdentity);
            $state = $this->meta($sqlite, 'state');
        }
        if ($state === 'SOURCE_COMPLETE_PENDING_PRODUCER') {
            if (! $producerExitConfirmed) {
                throw new InvalidArgumentException('The producer has not reported a clean exit; the offline graph remains unpublished.');
            }
            $this->setMeta($sqlite, 'producer_exit', 'PASS');
            $this->setMeta($sqlite, 'state', 'SOURCE_COMPLETE');
        } elseif ($producerExitConfirmed) {
            throw new InvalidArgumentException('Producer-exit confirmation does not match a pending producer checkpoint.');
        }

        if ($stopAfterSource) {
            if ($awaitProducerExit) {
                $this->setMeta($sqlite, 'producer_exit', 'PENDING');
                $this->setMeta($sqlite, 'state', 'SOURCE_COMPLETE_PENDING_PRODUCER');
            }

            return ['state' => $awaitProducerExit ? 'SOURCE_COMPLETE_PENDING_PRODUCER' : 'SOURCE_COMPLETE', 'artifact_ready' => false];
        }
        if ($this->meta($sqlite, 'state') !== 'COMPLETE') {
            $this->buildGraph($sqlite);
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
            if (! $resume || $exists !== self::FORMAT) {
                throw new InvalidArgumentException('Existing staging file is not a resumable Biyahe graph artifact.');
            }
            foreach ($source as $key => $value) {
                if ($this->meta($sqlite, 'source_identity_'.$key) !== $value) {
                    throw new InvalidArgumentException('Resume source identity does not match the staging checkpoint.');
                }
            }
            return;
        }
        if ($resume) {
            throw new InvalidArgumentException('Cannot resume a staging file without a Biyahe checkpoint.');
        }

        $sqlite->exec('CREATE TABLE source_nodes (id INTEGER PRIMARY KEY, latitude REAL NOT NULL, longitude REAL NOT NULL, in_scope INTEGER NOT NULL, walkable INTEGER NOT NULL, barrier TEXT NULL, crossing TEXT NULL)');
        $sqlite->exec('CREATE TABLE seen_way_ids (id INTEGER PRIMARY KEY)');
        $sqlite->exec('CREATE TABLE seen_relation_ids (id INTEGER PRIMARY KEY)');
        $sqlite->exec('CREATE TABLE source_ways (id INTEGER PRIMARY KEY, tags TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE required_nodes (id INTEGER PRIMARY KEY)');
        $sqlite->exec('CREATE TABLE way_refs (way_id INTEGER NOT NULL, sequence_index INTEGER NOT NULL, node_id INTEGER NOT NULL, PRIMARY KEY (way_id, sequence_index))');
        $sqlite->exec('CREATE INDEX way_refs_node_idx ON way_refs (node_id)');
        $sqlite->exec('CREATE TABLE graph_nodes (osm_node_id INTEGER PRIMARY KEY, latitude REAL NOT NULL, longitude REAL NOT NULL, in_scope INTEGER NOT NULL, walkable INTEGER NOT NULL, barrier TEXT NULL, crossing TEXT NULL)');
        $sqlite->exec('CREATE TABLE graph_edges (osm_way_id INTEGER NOT NULL, segment_index INTEGER NOT NULL, from_node_id INTEGER NOT NULL, to_node_id INTEGER NOT NULL, bidirectional INTEGER NOT NULL, length_meters REAL NOT NULL, highway TEXT NOT NULL, properties TEXT NOT NULL, geometry_wkt TEXT NOT NULL, PRIMARY KEY (osm_way_id, segment_index), FOREIGN KEY (from_node_id) REFERENCES graph_nodes(osm_node_id), FOREIGN KEY (to_node_id) REFERENCES graph_nodes(osm_node_id))');
        foreach (['format' => self::FORMAT, 'schema_version' => self::SCHEMA_VERSION, 'state' => 'INGESTING', 'input_complete' => false, 'routing_complete' => false] as $key => $value) {
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
        $this->setMeta($sqlite, 'extract_strategy', 'streaming_sqlite_ref_complete');
    }

    private function resetSource(PDO $sqlite, array $source): void
    {
        $sqlite->beginTransaction();
        foreach (['source_nodes', 'seen_way_ids', 'seen_relation_ids', 'source_ways', 'required_nodes', 'way_refs', 'graph_nodes', 'graph_edges'] as $table) {
            $sqlite->exec('DELETE FROM '.$table);
        }
        foreach (['source_nodes', 'source_ways', 'candidate_ways', 'relations', 'relation_restrictions', 'required_nodes', 'graph_nodes', 'graph_edges', 'component_count', 'graph_content_sha256'] as $key) {
            $sqlite->exec('DELETE FROM metadata WHERE key='. $sqlite->quote($key));
        }
        if (($source['timestamp'] ?? null) === null) {
            $sqlite->exec("DELETE FROM metadata WHERE key='source_timestamp'");
        }
        $this->setMeta($sqlite, 'state', 'INGESTING');
        $this->setMeta($sqlite, 'input_complete', false);
        foreach ($source as $key => $value) {
            $this->setMeta($sqlite, 'source_identity_'.$key, $value);
            if ($key !== 'timestamp' || $value !== null) {
                $this->setMeta($sqlite, 'source_'.$key, $value);
            }
        }
        $sqlite->commit();
    }

    private function ingest(PDO $sqlite, string $inputPath, array $source): void
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
        $wayInsert = $sqlite->prepare('INSERT INTO source_ways (id, tags) VALUES (?, ?)');
        $refInsert = $sqlite->prepare('INSERT INTO way_refs (way_id, sequence_index, node_id) VALUES (?, ?, ?)');
        $requiredInsert = $sqlite->prepare('INSERT OR IGNORE INTO required_nodes (id) VALUES (?)');
        $nodeCount = $wayCount = $relationCount = $candidateWays = $scopeNodes = $requiredCount = $restrictedRelations = 0;
        $rootSeen = $rootClosed = $waysStarted = $relationsStarted = false;
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
                    if ($waysStarted || $relationsStarted) {
                        throw new InvalidArgumentException('OSM XML nodes must precede ways and relations.');
                    }
                    $node = $this->readNode($reader);
                    $inside = PedestrianOsmPolicy::insideScope($node['lat'], $node['lon'], $scope);
                    $tags = $inside ? PedestrianOsmPolicy::tags($node['tags']) : [];
                    try {
                        $nodeInsert->execute([
                            $node['id'], $node['lat'], $node['lon'], $inside ? 1 : 0,
                            $inside && PedestrianOsmPolicy::blockedNode($tags) ? 0 : 1,
                            $tags['barrier'] ?? null,
                            $tags['crossing'] ?? (($tags['highway'] ?? null) === 'crossing' ? 'MAPPED' : null),
                        ]);
                    } catch (PDOException $exception) {
                        throw new InvalidArgumentException('Duplicate OSM node ID in source stream.', 0, $exception);
                    }
                    $nodeCount++;
                    if ($nodeCount > 10_000_000) {
                        throw new InvalidArgumentException('OSM source exceeds the supported node count.');
                    }
                    $scopeNodes += $inside ? 1 : 0;
                    if ($scopeNodes > max(1, (int) config('biyahe.pedestrian.maximum_input_nodes', 100_000))) {
                        throw new InvalidArgumentException('OSM source exceeds the configured in-scope node limit.');
                    }
                } elseif ($reader->name === 'way') {
                    $waysStarted = true;
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
                    $known = $this->lookupInsideNodes($sqlite, $way['refs']);
                    $candidate = false;
                    for ($index = 0, $last = count($way['refs']) - 1; $index < $last; $index++) {
                        if (($known[$way['refs'][$index]] ?? false) && ($known[$way['refs'][$index + 1]] ?? false)) {
                            $candidate = true;
                            break;
                        }
                    }
                    if ($candidate) {
                        if (++$candidateWays > max(1, (int) config('biyahe.pedestrian.maximum_input_ways', 25_000))) {
                            throw new InvalidArgumentException('OSM source exceeds the configured candidate-way limit.');
                        }
                        if (count($known) !== count(array_unique($way['refs']))) {
                            throw new InvalidArgumentException('A candidate way references a node missing from the source stream.');
                        }
                        $wayInsert->execute([$way['id'], json_encode($way['tags'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
                        foreach ($way['refs'] as $index => $nodeId) {
                            $refInsert->execute([$way['id'], $index, $nodeId]);
                            $requiredInsert->execute([$nodeId]);
                            $requiredCount += $requiredInsert->rowCount();
                        }
                        if ($requiredCount > max(1, (int) config('biyahe.pedestrian.maximum_input_nodes', 100_000))) {
                            throw new InvalidArgumentException('Candidate ways exceed the configured referenced-node limit.');
                        }
                    }
                } elseif ($reader->name === 'relation') {
                    $relationsStarted = true;
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
                    if (($relation['tags']['type'] ?? null) === 'restriction'
                        && (isset($relation['tags']['restriction:foot'])
                            || (isset($relation['tags']['restriction']) && ! in_array('foot', array_map('trim', explode(';', $relation['tags']['except'] ?? '')), true)))) {
                        $restrictedRelations++;
                    }
                }

                if (($nodeCount + $wayCount + $relationCount) % self::COMMIT_EVERY_ELEMENTS === 0) {
                    $this->writeProgress($sqlite, $nodeCount, $wayCount, $relationCount, $candidateWays, $scopeNodes, $requiredCount);
                    $sqlite->commit();
                    $transaction = false;
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
            if ($scopeNodes === 0 || $candidateWays === 0) {
                throw new InvalidArgumentException('OSM stream contains no pedestrian candidate segments inside the configured bounds.');
            }
            $this->writeProgress($sqlite, $nodeCount, $wayCount, $relationCount, $candidateWays, $scopeNodes, $requiredCount);
            $this->setMeta($sqlite, 'source_timestamp', $timestamp);
            $this->setMeta($sqlite, 'relation_restrictions', $restrictedRelations);
            $this->setMeta($sqlite, 'input_complete', true);
            $this->setMeta($sqlite, 'state', 'SOURCE_COMPLETE');
            $sqlite->commit();
            $transaction = false;
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

    private function buildGraph(PDO $sqlite): void
    {
        $sqlite->beginTransaction();
        $sqlite->exec('DELETE FROM graph_edges');
        $sqlite->exec('DELETE FROM graph_nodes');
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

        $sqlite->beginTransaction();
        $sqlite->exec('INSERT OR IGNORE INTO graph_nodes (osm_node_id, latitude, longitude, in_scope, walkable, barrier, crossing) SELECT id, latitude, longitude, in_scope, walkable, barrier, crossing FROM source_nodes WHERE in_scope=1 AND walkable=0');
        $nodeCount = (int) $sqlite->query('SELECT COUNT(*) FROM graph_nodes')->fetchColumn();
        $edgeCount = (int) $sqlite->query('SELECT COUNT(*) FROM graph_edges')->fetchColumn();
        if ($nodeCount === 0 || $edgeCount === 0) {
            throw new InvalidArgumentException('The offline graph has no usable pedestrian edges.');
        }
        $components = OfflinePedestrianGraphTopology::componentCount($sqlite);
        $contentHash = OfflinePedestrianGraphTopology::contentHash($sqlite);
        foreach ([
            'state' => 'COMPLETE', 'graph_nodes' => $nodeCount, 'graph_edges' => $edgeCount,
            'accepted_ways' => $acceptedWays, 'restricted_ways' => $restrictedWays,
            'component_count' => $components, 'graph_content_sha256' => $contentHash,
            'routing_complete' => (int) $this->meta($sqlite, 'relation_restrictions') === 0,
            'crs' => 'EPSG:4326',
        ] as $key => $value) {
            $this->setMeta($sqlite, $key, $value);
        }
        $sqlite->commit();
    }

    private function lookupInsideNodes(PDO $sqlite, array $references): array
    {
        $inside = [];
        foreach (array_chunk(array_values(array_unique($references)), 500) as $chunk) {
            $statement = $sqlite->prepare('SELECT id, in_scope FROM source_nodes WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
            $statement->execute($chunk);
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $inside[(int) $row['id']] = (bool) $row['in_scope'];
            }
        }

        return $inside;
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

    private function writeProgress(PDO $sqlite, int $nodes, int $ways, int $relations, int $candidates, int $scopeNodes, int $requiredNodes): void
    {
        foreach (['source_nodes' => $nodes, 'source_ways' => $ways, 'relations' => $relations, 'candidate_ways' => $candidates, 'scope_nodes' => $scopeNodes, 'required_nodes' => $requiredNodes] as $key => $value) {
            $this->setMeta($sqlite, $key, $value);
        }
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
        if (! is_array($owned) || ! rename($workPath, $outputPath)) {
            $current = @lstat($outputPath);
            if (is_array($owned) && is_array($current) && $owned['dev'] === $current['dev'] && $owned['ino'] === $current['ino']) {
                @unlink($outputPath);
            }
            throw new RuntimeException('Could not atomically publish the complete offline graph.');
        }
    }
}
