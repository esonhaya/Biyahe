<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use XMLReader;

/**
 * Select a bounded, reference-complete subset from an Osmium XML stream.
 *
 * Nodes are indexed in a temporary SQLite file so a regional PBF can be
 * streamed without retaining its node/way set in PHP memory. Only nodes in
 * the configured scope and ways with an in-scope segment are emitted.
 */
final class PreparePedestrianOsmStream extends Command
{
    protected $signature = 'biyahe:pedestrian:prepare-osm-stream {input : OSM XML path or - to read XML from stdin} {--output= : New bounded JSON output path} {--source-path= : Local source PBF whose checksums will be verified} {--source-md5= : Expected source MD5 for comparison} {--source-sha256= : Expected source SHA-256 for comparison} {--source-url= : Upstream OSM extract URL} {--source-timestamp= : OSM snapshot timestamp when available} {--osmium-version= : Osmium version used to stream the PBF} {--bounds= : Test-only bounds as south,west,north,east inside configured coverage}';

    protected $description = 'Select a bounded reference-complete pedestrian extract using a disk-backed SQLite index.';

    private const MAX_REFERENCES_PER_WAY = 100_000;

    public function handle(): int
    {
        $previousLibxmlSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $this->prepare();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);
        }

        return self::SUCCESS;
    }

    private function prepare(): void
    {
        $inputPath = (string) $this->argument('input');
        $outputPath = (string) $this->option('output');
        $sourcePath = (string) $this->option('source-path');
        $sourceUrl = (string) $this->option('source-url');
        $expectedMd5 = strtolower(trim((string) $this->option('source-md5')));
        $expectedSha256 = strtolower(trim((string) $this->option('source-sha256')));
        $sourceTimestamp = trim((string) $this->option('source-timestamp'));
        $osmiumVersion = trim((string) $this->option('osmium-version'));
        [$scope, $testOnly] = $this->scope((string) $this->option('bounds'));
        $maxBytes = max(1024, (int) config('biyahe.pedestrian.maximum_input_bytes', 20_000_000));
        $maxNodes = max(1, (int) config('biyahe.pedestrian.maximum_input_nodes', 100_000));
        $maxWays = max(1, (int) config('biyahe.pedestrian.maximum_input_ways', 25_000));

        if ($inputPath !== '-' && (! is_file($inputPath) || ! is_readable($inputPath))) {
            throw new InvalidArgumentException('Input must be a readable OSM XML stream or - for stdin.');
        }
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidArgumentException('A readable local source PBF is required for provenance verification.');
        }
        if ($outputPath === '' || ! is_dir(dirname($outputPath)) || ! is_writable(dirname($outputPath)) || file_exists($outputPath)) {
            throw new InvalidArgumentException('Provide a new output path in an existing writable directory.');
        }
        if ($inputPath !== '-' && realpath($inputPath) === realpath($outputPath)) {
            throw new InvalidArgumentException('Input and output paths must be different.');
        }
        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Source URL must be a valid HTTP or HTTPS URL.');
        }
        $urlFile = basename(rawurldecode((string) parse_url($sourceUrl, PHP_URL_PATH)));
        if ($urlFile === '' || $urlFile !== basename($sourcePath)) {
            throw new InvalidArgumentException('Source URL filename must match the verified local source file.');
        }
        if (($expectedMd5 !== '' && ! preg_match('/^[a-f0-9]{32}$/', $expectedMd5))
            || ($expectedSha256 !== '' && ! preg_match('/^[a-f0-9]{64}$/', $expectedSha256))) {
            throw new InvalidArgumentException('Expected source checksums are malformed.');
        }
        if ($osmiumVersion === '' || strlen($osmiumVersion) > 80) {
            throw new InvalidArgumentException('Osmium version metadata is required and must be bounded.');
        }
        if ($sourceTimestamp !== '') {
            $sourceTimestamp = $this->timestamp($sourceTimestamp);
        } else {
            $sourceTimestamp = null;
        }
        $sourceMd5 = hash_file('md5', $sourcePath);
        $sourceSha256 = hash_file('sha256', $sourcePath);
        if (! is_string($sourceMd5) || ! is_string($sourceSha256)
            || ($expectedMd5 !== '' && ! hash_equals($expectedMd5, $sourceMd5))
            || ($expectedSha256 !== '' && ! hash_equals($expectedSha256, $sourceSha256))) {
            throw new InvalidArgumentException('Local source PBF does not match the expected checksum.');
        }
        $sourceFile = basename($sourcePath);
        if ($sourceFile === '' || strlen($sourceFile) > 255 || str_contains($sourceFile, "\0")) {
            throw new InvalidArgumentException('Source filename is malformed or too long.');
        }

        $reader = new XMLReader;
        $opened = $inputPath === '-'
            ? $reader->open('php://stdin', null, LIBXML_NONET | LIBXML_COMPACT)
            : $reader->open($inputPath, null, LIBXML_NONET | LIBXML_COMPACT);
        if (! $opened) {
            throw new InvalidArgumentException('Input is not readable OSM XML.');
        }

        $sqlitePath = tempnam(dirname($outputPath), '.biyahe-osm-index-');
        $temporaryOutput = tempnam(dirname($outputPath), '.biyahe-osm-json-');
        if ($sqlitePath === false || $temporaryOutput === false) {
            $reader->close();
            if (is_string($sqlitePath)) {
                @unlink($sqlitePath);
            }
            if (is_string($temporaryOutput)) {
                @unlink($temporaryOutput);
            }
            throw new RuntimeException('Could not allocate bounded scratch files beside the output.');
        }

        $sqlite = null;
        $json = null;
        try {
            $sqlite = new PDO('sqlite:'.$sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $sqlite->exec('PRAGMA journal_mode=OFF');
            $sqlite->exec('PRAGMA synchronous=OFF');
            $sqlite->exec('PRAGMA temp_store=FILE');
            $sqlite->exec('PRAGMA cache_size=-16384');
            $sqlite->exec('CREATE TABLE nodes (id INTEGER PRIMARY KEY, latitude REAL NOT NULL, longitude REAL NOT NULL, inside_scope INTEGER NOT NULL, tags TEXT NOT NULL)');
            $sqlite->exec('CREATE TABLE candidate_ways (id INTEGER PRIMARY KEY, tags TEXT NOT NULL, refs TEXT NOT NULL)');
            $sqlite->exec('CREATE TABLE required_nodes (id INTEGER PRIMARY KEY)');

            $this->streamElements($reader, $sqlite, $maxNodes, $maxWays, $scope, $sourceTimestamp);

            $scopeNodes = (int) $sqlite->query('SELECT COUNT(*) FROM nodes WHERE inside_scope=1')->fetchColumn();
            $candidateWays = (int) $sqlite->query('SELECT COUNT(*) FROM candidate_ways')->fetchColumn();
            $missingReferencedNodes = (int) $sqlite->query('SELECT COUNT(*) FROM required_nodes AS required LEFT JOIN nodes ON nodes.id = required.id WHERE nodes.id IS NULL')->fetchColumn();
            if ($missingReferencedNodes > 0) {
                throw new InvalidArgumentException('OSM candidate way references a node missing from the source stream.');
            }
            $outputNodes = (int) $sqlite->query('SELECT COUNT(*) FROM nodes WHERE inside_scope=1 OR id IN (SELECT id FROM required_nodes)')->fetchColumn();
            if ($scopeNodes === 0 || $candidateWays === 0 || $outputNodes > $maxNodes || $candidateWays > $maxWays) {
                throw new InvalidArgumentException('Stream contains no bounded pedestrian candidates or exceeds configured node/way limits.');
            }

            $sourceElements = $outputNodes + $candidateWays;
            $timestamp = $sourceTimestamp;
            if ($timestamp === null) {
                $timestamp = $this->rootTimestamp($inputPath);
            }
            if ($timestamp === null) {
                throw new InvalidArgumentException('OSM snapshot timestamp is required in the stream or source options.');
            }

            $metadata = [
                'format' => 'osmium-osm-xml-v1',
                'source' => 'OPENSTREETMAP',
                'source_url' => $sourceUrl,
                'source_file' => basename($sourceFile),
                'source_checksum_md5' => $sourceMd5,
                'source_checksum_sha256' => $sourceSha256,
                'source_timestamp' => $timestamp,
                'source_elements' => $sourceElements,
                'osmium_version' => $osmiumVersion,
                'extract_strategy' => 'streaming_sqlite_ref_complete',
                'extract_bounds' => $scope,
                'license' => 'ODbL-1.0',
                'attribution' => '© OpenStreetMap contributors',
            ];
            if ($testOnly) {
                $metadata['test_only'] = true;
            }

            $json = fopen($temporaryOutput, 'wb');
            if ($json === false) {
                throw new RuntimeException('Could not write the bounded JSON output.');
            }
            $written = 0;
            $header = json_encode([
                'osm3s' => ['timestamp_osm_base' => $timestamp],
                'biyahe_source' => $metadata,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->writeLimited($json, substr($header, 0, -1).',"elements":[', $written, $maxBytes);

            $first = true;
            $nodeQuery = $sqlite->query('SELECT id, latitude, longitude, inside_scope, tags FROM nodes WHERE inside_scope=1 OR id IN (SELECT id FROM required_nodes) ORDER BY id');
            while ($node = $nodeQuery->fetch(PDO::FETCH_ASSOC)) {
                $tags = (bool) $node['inside_scope'] ? json_decode((string) $node['tags'], true, 32, JSON_THROW_ON_ERROR) : [];
                $element = [
                    'type' => 'node',
                    'id' => (int) $node['id'],
                    'lat' => (float) $node['latitude'],
                    'lon' => (float) $node['longitude'],
                    'tags' => (object) $tags,
                ];
                $this->writeElement($json, $element, $first, $written, $maxBytes);
            }

            $wayQuery = $sqlite->query('SELECT id, tags, refs FROM candidate_ways ORDER BY id');
            while ($way = $wayQuery->fetch(PDO::FETCH_ASSOC)) {
                $element = [
                    'type' => 'way',
                    'id' => (int) $way['id'],
                    'nodes' => json_decode((string) $way['refs'], true, 32, JSON_THROW_ON_ERROR),
                    'tags' => (object) json_decode((string) $way['tags'], true, 32, JSON_THROW_ON_ERROR),
                ];
                $this->writeElement($json, $element, $first, $written, $maxBytes);
            }

            $this->writeLimited($json, ']}', $written, $maxBytes);
            fclose($json);
            $json = null;
            $reader->close();
            unset($nodeQuery, $wayQuery);
            $sqlite = null;
            $this->publishNoClobber($temporaryOutput, $outputPath);
            @unlink($temporaryOutput);

            $this->line('scope_nodes: '.$scopeNodes);
            $this->line('candidate_ways: '.$candidateWays);
            $this->line('output_nodes: '.$outputNodes);
            $this->line('output_bytes: '.filesize($outputPath));
            $this->line('snapshot_timestamp: '.$timestamp);
            $this->line('output_file: '.$outputPath);
        } finally {
            if (is_resource($json)) {
                fclose($json);
            }
            $reader->close();
            $sqlite = null;
            @unlink($sqlitePath);
            @unlink($temporaryOutput);
        }
    }

    private function streamElements(XMLReader $reader, PDO $sqlite, int $maxNodes, int $maxWays, array $scope, ?string &$sourceTimestamp): void
    {
        $insertNode = $sqlite->prepare('INSERT INTO nodes (id, latitude, longitude, inside_scope, tags) VALUES (?, ?, ?, ?, ?)');
        $insertWay = $sqlite->prepare('INSERT INTO candidate_ways (id, tags, refs) VALUES (?, ?, ?)');
        $insertRequiredNode = $sqlite->prepare('INSERT OR IGNORE INTO required_nodes (id) VALUES (?)');
        $insideNodeIds = [];
        $nodeCount = 0;
        $wayCount = 0;
        $candidateWayCount = 0;
        $requiredNodeCount = 0;
        $rootSeen = false;
        $waysStarted = false;
        $inTransaction = false;

        $sqlite->beginTransaction();
        $inTransaction = true;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if (! $rootSeen) {
                if ($reader->name !== 'osm') {
                    throw new InvalidArgumentException('Input does not begin with an OSM XML root.');
                }
                $rootSeen = true;
                if ($sourceTimestamp === null && is_string($rootValue = $reader->getAttribute('timestamp'))) {
                    $sourceTimestamp = $this->timestamp($rootValue);
                }
                continue;
            }
            if ($reader->name === 'node') {
                if ($waysStarted) {
                    throw new InvalidArgumentException('OSM XML nodes must precede ways for reference-complete streaming.');
                }
                $nodeCount++;
                if ($nodeCount > 10_000_000) {
                    throw new InvalidArgumentException('OSM source exceeds the supported streaming node count.');
                }
                $node = $this->readNode($reader);
                $inside = $node['lat'] >= (float) $scope['min_latitude'] && $node['lat'] <= (float) $scope['max_latitude']
                    && $node['lon'] >= (float) $scope['min_longitude'] && $node['lon'] <= (float) $scope['max_longitude'];
                if ($inside) {
                    $insideNodeIds[$node['id']] = true;
                    if (count($insideNodeIds) > $maxNodes) {
                        throw new InvalidArgumentException('OSM source exceeds the configured in-scope node limit.');
                    }
                }
                $insertNode->execute([$node['id'], $node['lat'], $node['lon'], $inside ? 1 : 0, json_encode($inside ? $node['tags'] : [], JSON_THROW_ON_ERROR)]);
            } elseif ($reader->name === 'way') {
                $waysStarted = true;
                $wayCount++;
                if ($wayCount > 10_000_000) {
                    throw new InvalidArgumentException('OSM source exceeds the supported streaming way count.');
                }
                $way = $this->readWay($reader);
                $candidate = false;
                for ($index = 0, $last = count($way['refs']) - 1; $index < $last; $index++) {
                    if (isset($insideNodeIds[$way['refs'][$index]], $insideNodeIds[$way['refs'][$index + 1]])) {
                        $candidate = true;
                        break;
                    }
                }
                if ($candidate) {
                    $candidateWayCount++;
                    if ($candidateWayCount > $maxWays) {
                        throw new InvalidArgumentException('OSM source exceeds the configured candidate-way limit.');
                    }
                    $insertWay->execute([$way['id'], json_encode($way['tags'], JSON_THROW_ON_ERROR), json_encode($way['refs'], JSON_THROW_ON_ERROR)]);
                    foreach ($way['refs'] as $reference) {
                        $insertRequiredNode->execute([$reference]);
                        $requiredNodeCount += $insertRequiredNode->rowCount();
                        if ($requiredNodeCount > $maxNodes) {
                            throw new InvalidArgumentException('OSM candidate ways exceed the configured referenced-node limit.');
                        }
                    }
                }
            }

            if (($nodeCount + $wayCount) % 25_000 === 0) {
                $sqlite->commit();
                $inTransaction = false;
                $sqlite->beginTransaction();
                $inTransaction = true;
            }
        }
        if ($inTransaction) {
            $sqlite->commit();
        }
        if (! $rootSeen || $nodeCount === 0 || $wayCount === 0 || libxml_get_errors() !== []) {
            throw new InvalidArgumentException('OSM XML is empty, malformed, or incomplete.');
        }
    }

    /** @return array{id: int, lat: float, lon: float, tags: array<string, string>} */
    private function readNode(XMLReader $reader): array
    {
        $id = $this->positiveId($reader->getAttribute('id'));
        $lat = $reader->getAttribute('lat');
        $lon = $reader->getAttribute('lon');
        if (! is_numeric($lat) || ! is_numeric($lon) || ! is_finite((float) $lat) || ! is_finite((float) $lon)
            || (float) $lat < -90 || (float) $lat > 90 || (float) $lon < -180 || (float) $lon > 180) {
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

        return ['id' => $id, 'lat' => (float) $lat, 'lon' => (float) $lon, 'tags' => $tags];
    }

    /** @return array{id: int, refs: list<int>, tags: array<string, string>} */
    private function readWay(XMLReader $reader): array
    {
        $id = $this->positiveId($reader->getAttribute('id'));
        $refs = [];
        $tags = [];
        $this->readChildren($reader, static function (XMLReader $child) use (&$refs, &$tags): void {
            if ($child->name === 'nd') {
                if (count($refs) >= self::MAX_REFERENCES_PER_WAY) {
                    throw new InvalidArgumentException('OSM way exceeds the bounded reference count.');
                }
                $refs[] = self::positiveId($child->getAttribute('ref'));
            } elseif ($child->name === 'tag') {
                $key = $child->getAttribute('k');
                $value = $child->getAttribute('v');
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $tags[$key] = $value;
                }
            }
        });
        if (count($refs) < 2) {
            throw new InvalidArgumentException('OSM ways require at least two positive node references.');
        }

        return ['id' => $id, 'refs' => $refs, 'tags' => $tags];
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

    private function writeElement($stream, array $element, bool &$first, int &$written, int $limit): void
    {
        $this->writeLimited($stream, ($first ? '' : ',').json_encode($element, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $written, $limit);
        $first = false;
    }

    private function writeLimited($stream, string $contents, int &$written, int $limit): void
    {
        if ($written + strlen($contents) > $limit) {
            throw new InvalidArgumentException('Prepared JSON exceeds the configured pedestrian-import byte limit.');
        }
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $count = fwrite($stream, substr($contents, $offset));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Could not write the bounded JSON output.');
            }
            $offset += $count;
        }
        $written += $length;
    }

    private function publishNoClobber(string $temporaryPath, string $outputPath): void
    {
        $reservation = @fopen($outputPath, 'x');
        if ($reservation === false) {
            throw new RuntimeException('Output path already exists or cannot be reserved safely.');
        }
        $ownedStat = fstat($reservation);
        fclose($reservation);
        if (! is_array($ownedStat) || ! rename($temporaryPath, $outputPath)) {
            $currentStat = @lstat($outputPath);
            if (is_array($ownedStat) && is_array($currentStat)
                && $ownedStat['dev'] === $currentStat['dev'] && $ownedStat['ino'] === $currentStat['ino']) {
                @unlink($outputPath);
            }
            throw new RuntimeException('Could not atomically publish the bounded JSON output.');
        }
    }

    private static function positiveId(?string $raw): int
    {
        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw < 1) {
            throw new InvalidArgumentException('OSM elements require positive integer IDs.');
        }

        return (int) $raw;
    }

    private function timestamp(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            throw new InvalidArgumentException('OSM source timestamp is malformed.');
        }
    }

    /** @return array{array<string, float>, bool} */
    private function scope(string $bounds): array
    {
        $configured = config('biyahe.pedestrian.coverage');
        if ($bounds === '') {
            return [$configured, false];
        }
        $values = array_map('trim', explode(',', $bounds));
        if (count($values) !== 4 || array_filter($values, static fn (string $value): bool => ! is_numeric($value)) !== []) {
            throw new InvalidArgumentException('Test bounds must contain south,west,north,east numeric values.');
        }
        [$south, $west, $north, $east] = array_map('floatval', $values);
        if (! is_finite($south) || ! is_finite($west) || ! is_finite($north) || ! is_finite($east)
            || $south >= $north || $west >= $east
            || $south < (float) $configured['min_latitude'] || $north > (float) $configured['max_latitude']
            || $west < (float) $configured['min_longitude'] || $east > (float) $configured['max_longitude']) {
            throw new InvalidArgumentException('Test bounds must be an ordered subset of configured Cebu coverage.');
        }

        return [[
            ...$configured,
            'min_latitude' => $south,
            'min_longitude' => $west,
            'max_latitude' => $north,
            'max_longitude' => $east,
        ], true];
    }

    private function rootTimestamp(string $inputPath): ?string
    {
        if ($inputPath === '-') {
            return null;
        }
        $reader = new XMLReader;
        if (! $reader->open($inputPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return null;
        }
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'osm') {
                    $value = $reader->getAttribute('timestamp');

                    return is_string($value) ? $this->timestamp($value) : null;
                }
            }
        } finally {
            $reader->close();
        }

        return null;
    }
}
