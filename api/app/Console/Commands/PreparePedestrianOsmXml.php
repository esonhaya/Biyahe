<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use XMLReader;

final class PreparePedestrianOsmXml extends Command
{
    protected $signature = 'biyahe:pedestrian:prepare-osm-xml {file : Filtered, reference-complete OSM XML from Osmium} {--output= : Bounded Overpass-compatible JSON output path} {--source-url= : Upstream OSM extract URL} {--source-file= : Upstream extract filename} {--source-md5= : Verified upstream MD5 checksum} {--source-timestamp= : OSM snapshot timestamp when available} {--source-elements= : Total elements in the bounded source extract} {--osmium-version= : Osmium version used for extraction}';

    protected $description = 'Convert a bounded Osmium OSM XML extract into the validated pedestrian-import format.';

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
        $inputPath = (string) $this->argument('file');
        $outputPath = (string) $this->option('output');
        $sourceUrl = (string) $this->option('source-url');
        $sourceFile = trim((string) $this->option('source-file'));
        $sourceMd5 = strtolower(trim((string) $this->option('source-md5')));
        $sourceTimestamp = trim((string) $this->option('source-timestamp'));
        $sourceElements = $this->option('source-elements');
        $osmiumVersion = trim((string) $this->option('osmium-version'));
        $maximumXmlBytes = max(1024, (int) config('biyahe.pedestrian.maximum_source_xml_bytes', 100_000_000));
        $maximumJsonBytes = max(1024, (int) config('biyahe.pedestrian.maximum_input_bytes', 20_000_000));

        if (! is_file($inputPath) || ! is_readable($inputPath) || filesize($inputPath) === false || filesize($inputPath) > $maximumXmlBytes) {
            throw new InvalidArgumentException('Input must be readable bounded OSM XML within the configured byte limit.');
        }
        if ($outputPath === '' || ! is_dir(dirname($outputPath)) || ! is_writable(dirname($outputPath))) {
            throw new InvalidArgumentException('Provide an output path in an existing writable directory.');
        }
        if (realpath($inputPath) === realpath($outputPath)) {
            throw new InvalidArgumentException('Input and output paths must be different.');
        }
        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Source URL must be a valid HTTP or HTTPS URL.');
        }
        if ($sourceFile === '' || strlen($sourceFile) > 255 || str_contains($sourceFile, "\0")) {
            throw new InvalidArgumentException('Source filename is required and must be bounded.');
        }
        if ($sourceMd5 !== '' && ! preg_match('/^[a-f0-9]{32}$/', $sourceMd5)) {
            throw new InvalidArgumentException('Source MD5 must contain exactly 32 hexadecimal characters.');
        }
        if ($sourceElements !== null && (! ctype_digit((string) $sourceElements) || (int) $sourceElements > 10_000_000)) {
            throw new InvalidArgumentException('Source element count must be a bounded non-negative integer.');
        }
        if (strlen($osmiumVersion) > 80) {
            throw new InvalidArgumentException('Osmium version metadata is too long.');
        }
        if ($sourceTimestamp !== '') {
            try {
                $sourceTimestamp = (new DateTimeImmutable($sourceTimestamp))->format(DATE_ATOM);
            } catch (\Throwable) {
                throw new InvalidArgumentException('OSM source timestamp is malformed.');
            }
        } else {
            $sourceTimestamp = '';
        }

        $reader = new XMLReader;
        if (! $reader->open($inputPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new InvalidArgumentException('Input is not readable OSM XML.');
        }
        $seenOsmRoot = false;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'osm') {
                continue;
            }
            $seenOsmRoot = true;
            if ($sourceTimestamp === '' && is_string($reader->getAttribute('timestamp'))) {
                try {
                    $sourceTimestamp = (new DateTimeImmutable((string) $reader->getAttribute('timestamp')))->format(DATE_ATOM);
                } catch (\Throwable) {
                    $reader->close();
                    throw new InvalidArgumentException('OSM XML snapshot timestamp is malformed.');
                }
            }
            break;
        }
        if (! $seenOsmRoot) {
            $reader->close();
            throw new InvalidArgumentException('Input does not contain an OSM XML root.');
        }

        $temporaryPath = tempnam(dirname($outputPath), '.biyahe-osm-');
        if ($temporaryPath === false) {
            $reader->close();
            throw new RuntimeException('Could not allocate an output file in the requested directory.');
        }

        $output = fopen($temporaryPath, 'wb');
        if ($output === false) {
            $reader->close();
            @unlink($temporaryPath);
            throw new RuntimeException('Could not write the prepared extract.');
        }

        $nodeCount = 0;
        $wayCount = 0;
        $firstElement = true;
        $timestamp = $sourceTimestamp === '' ? null : $sourceTimestamp;
        $metadata = [
            'format' => 'osmium-osm-xml-v1',
            'source' => 'OPENSTREETMAP',
            'source_url' => $sourceUrl,
            'source_file' => basename($sourceFile),
            'source_checksum_md5' => $sourceMd5 === '' ? null : $sourceMd5,
            'source_timestamp' => $timestamp,
            'source_elements' => $sourceElements === null ? null : (int) $sourceElements,
            'osmium_version' => $osmiumVersion === '' ? null : $osmiumVersion,
            'extract_strategy' => 'complete_ways',
            'extract_bounds' => config('biyahe.pedestrian.coverage'),
            'license' => 'ODbL-1.0',
            'attribution' => '© OpenStreetMap contributors',
        ];
        $header = json_encode([
            'osm3s' => ['timestamp_osm_base' => $timestamp],
            'biyahe_source' => $metadata,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->writeAll($output, substr($header, 0, -1).',"elements":[');

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if (! in_array($reader->name, ['node', 'way'], true)) {
                    continue;
                }

                $element = $this->readElement($reader, $reader->name);
                if ($element['type'] === 'node') {
                    $nodeCount++;
                } else {
                    $wayCount++;
                }
                if (! $firstElement) {
                    $this->writeAll($output, ',');
                }
                $this->writeAll($output, json_encode($element, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $firstElement = false;
            }

            if (libxml_get_errors() !== []) {
                throw new InvalidArgumentException('OSM XML is malformed or incomplete.');
            }

            if ($nodeCount === 0 || $wayCount === 0) {
                throw new InvalidArgumentException('OSM XML must contain referenced nodes and ways.');
            }

            $this->writeAll($output, ']}');
            fclose($output);
            $reader->close();

            if (filesize($temporaryPath) === false || filesize($temporaryPath) > $maximumJsonBytes) {
                throw new InvalidArgumentException('Prepared JSON exceeds the configured pedestrian-import byte limit.');
            }
            if (! rename($temporaryPath, $outputPath)) {
                throw new RuntimeException('Could not atomically publish the prepared extract.');
            }
        } catch (\Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
            }
            $reader->close();
            @unlink($temporaryPath);
            throw $exception;
        }

        $this->line('source_nodes: '.$nodeCount);
        $this->line('source_ways: '.$wayCount);
        $this->line('snapshot_timestamp: '.($timestamp ?? 'not supplied'));
        $this->line('prepared_bytes: '.filesize($outputPath));
        $this->line('prepared_file: '.$outputPath);
    }

    /** @return array<string, mixed> */
    private function readElement(XMLReader $reader, string $kind): array
    {
        $id = $reader->getAttribute('id');
        if (! is_string($id) || ! ctype_digit($id) || (int) $id < 1) {
            throw new InvalidArgumentException('OSM elements require positive integer IDs.');
        }

        $element = ['type' => $kind, 'id' => (int) $id];
        if ($kind === 'node') {
            $latitude = $reader->getAttribute('lat');
            $longitude = $reader->getAttribute('lon');
            if (! is_numeric($latitude) || ! is_numeric($longitude)) {
                throw new InvalidArgumentException('OSM nodes require latitude and longitude.');
            }
            $element['lat'] = (float) $latitude;
            $element['lon'] = (float) $longitude;
        }

        $tags = [];
        $references = [];
        $depth = $reader->depth;
        if (! $reader->isEmptyElement) {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                    break;
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->name === 'tag') {
                    $key = $reader->getAttribute('k');
                    $value = $reader->getAttribute('v');
                    if (is_string($key) && $key !== '' && is_string($value)) {
                        $tags[$key] = $value;
                    }
                } elseif ($kind === 'way' && $reader->name === 'nd') {
                    $reference = $reader->getAttribute('ref');
                    if (! is_string($reference) || ! ctype_digit($reference) || (int) $reference < 1) {
                        throw new InvalidArgumentException('OSM ways require positive integer node references.');
                    }
                    $references[] = (int) $reference;
                }
            }
        }

        $element['tags'] = (object) $tags;
        if ($kind === 'way') {
            if (count($references) < 2) {
                throw new InvalidArgumentException('OSM ways require at least two node references.');
            }
            $element['nodes'] = $references;
        }

        return $element;
    }

    private function writeAll($stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the prepared extract.');
            }
            $offset += $written;
        }
    }
}
