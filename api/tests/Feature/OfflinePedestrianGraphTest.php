<?php

namespace Tests\Feature;

use App\Services\Routing\OfflinePedestrianGraphStager;
use App\Services\Routing\OfflinePedestrianGraphValidator;
use PDO;
use Tests\TestCase;

final class OfflinePedestrianGraphTest extends TestCase
{
    public function test_offline_graph_preserves_way_order_access_and_disconnected_components(): void
    {
        $files = $this->fixtureFiles();
        $xml = $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<node id="3" lat="10.3002" lon="123.8700"/>',
            '<node id="4" lat="10.3003" lon="123.8700"/>',
            '<node id="5" lat="10.3050" lon="123.8750"/>',
            '<node id="6" lat="10.3051" lon="123.8750"/>',
            '<node id="7" lat="10.3052" lon="123.8750"><tag k="barrier" v="fence"/></node>',
            '<node id="8" lat="10.3004" lon="124.0000"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><nd ref="3"/><tag k="highway" v="footway"/><tag k="oneway:foot" v="yes"/></way>',
            '<way id="200"><nd ref="4"/><nd ref="3"/><tag k="highway" v="footway"/></way>',
            '<way id="300"><nd ref="7"/><nd ref="6"/><tag k="highway" v="footway"/></way>',
            '<way id="400"><nd ref="8"/><nd ref="4"/><tag k="highway" v="footway"/></way>',
        ]);
        file_put_contents($files['xml'], $xml);

        try {
            $result = $this->build($files);
            $this->assertSame('PASS', $result['status']);
            $this->assertSame(2, $result['components']);
            $this->assertSame(3, $result['edges']);
            $sqlite = new PDO('sqlite:'.$files['output']);
            $edges = $sqlite->query('SELECT osm_way_id, segment_index, from_node_id, to_node_id, bidirectional FROM graph_edges ORDER BY osm_way_id, segment_index')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame([
                ['osm_way_id' => 100, 'segment_index' => 0, 'from_node_id' => 1, 'to_node_id' => 2, 'bidirectional' => 0],
                ['osm_way_id' => 100, 'segment_index' => 1, 'from_node_id' => 2, 'to_node_id' => 3, 'bidirectional' => 0],
                ['osm_way_id' => 200, 'segment_index' => 0, 'from_node_id' => 4, 'to_node_id' => 3, 'bidirectional' => 1],
            ], $edges);
            $this->assertSame(1, (int) $sqlite->query('SELECT COUNT(*) FROM graph_nodes WHERE osm_node_id=7 AND walkable=0')->fetchColumn());
            $this->assertSame(0, (int) $sqlite->query('SELECT COUNT(*) FROM graph_edges WHERE osm_way_id=300 OR osm_way_id=400')->fetchColumn());
            $this->assertSame(0, (int) $sqlite->query('SELECT COUNT(*) FROM graph_nodes WHERE osm_node_id=8')->fetchColumn());
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_offline_scope_cap_is_separate_from_the_legacy_input_node_cap(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<node id="3" lat="10.3002" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));
        config([
            'biyahe.pedestrian.maximum_input_nodes' => 2,
            'biyahe.pedestrian.maximum_offline_scope_nodes' => 3,
        ]);

        try {
            $result = $this->build($files);
            $this->assertSame('PASS', $result['status']);
            $this->assertSame(2, $result['nodes']);
            $this->assertSame(1, $result['edges']);

            $limitedOutput = $files['directory'].'/graph-limited.sqlite';
            config(['biyahe.pedestrian.maximum_offline_scope_nodes' => 2]);
            try {
                app(OfflinePedestrianGraphStager::class)->build($files['xml'], $limitedOutput, $files['source'], $files['source_meta']);
                $this->fail('The offline scope cap should still reject excess source nodes.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('in-scope node limit', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($limitedOutput);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_source_complete_checkpoint_resumes_without_rereading_xml_and_never_publishes_early(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            $incomplete = app(OfflinePedestrianGraphStager::class)->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], false, true);
            $this->assertSame('SOURCE_COMPLETE', $incomplete['state']);
            $this->assertFalse($incomplete['artifact_ready']);
            $this->assertFileDoesNotExist($files['output']);
            $this->assertFileExists($files['output'].'.building');
            unlink($files['xml']);

            $complete = app(OfflinePedestrianGraphStager::class)->build('-', $files['output'], $files['source'], $files['source_meta'], true);
            $this->assertSame('PASS', $complete['status']);
            $this->assertFileExists($files['output']);
            $this->assertFileDoesNotExist($files['output'].'.building');
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_pipeline_checkpoint_requires_a_successful_producer_exit_before_finalization(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            $stager = app(OfflinePedestrianGraphStager::class);
            $result = $stager->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], false, true, true);
            $this->assertSame('SOURCE_COMPLETE_PENDING_PRODUCER', $result['state']);
            try {
                $stager->build('-', $files['output'], $files['source'], $files['source_meta'], true);
                $this->fail('A pipeline checkpoint must wait for the producer exit result.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('has not reported a clean exit', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($files['output']);
            $result = $stager->build('-', $files['output'], $files['source'], $files['source_meta'], true, false, false, true);
            $this->assertSame('PASS', $result['status']);
            $this->assertFileExists($files['output']);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_regenerating_the_same_source_produces_the_same_graph_fingerprint(): void
    {
        $files = $this->fixtureFiles();
        $secondOutput = $files['directory'].'/graph-retry.sqlite';
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            $this->build($files);
            $first = app(OfflinePedestrianGraphStager::class)->build($files['xml'], $secondOutput, $files['source'], $files['source_meta']);
            $firstSqlite = new PDO('sqlite:'.$files['output']);
            $secondSqlite = new PDO('sqlite:'.$secondOutput);
            $this->assertSame(
                $firstSqlite->query("SELECT value FROM metadata WHERE key='graph_content_sha256'")->fetchColumn(),
                $secondSqlite->query("SELECT value FROM metadata WHERE key='graph_content_sha256'")->fetchColumn(),
            );
            $this->assertSame('PASS', $first['status']);
            $this->assertSame(1, $first['edges']);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_truncated_or_missing_reference_streams_do_not_publish_ready_artifacts(): void
    {
        $files = $this->fixtureFiles();
        $valid = '<node id="1" lat="10.3000" lon="123.8700"/><node id="2" lat="10.3001" lon="123.8700"/><way id="100"><nd ref="1"/><nd ref="2"/><nd ref="999"/><tag k="highway" v="footway"/></way>';
        file_put_contents($files['xml'], $this->xml([$valid]));
        try {
            try {
                $this->build($files);
                $this->fail('Candidate way with a missing reference should be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('missing from the source stream', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($files['output']);
            $this->removeFixtureFiles($files);

            $files = $this->fixtureFiles();
            file_put_contents($files['xml'], substr($this->xml([
                '<node id="1" lat="10.3000" lon="123.8700"/>',
                '<node id="2" lat="10.3001" lon="123.8700"/>',
                '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
            ]), 0, -12));
            try {
                $this->build($files);
                $this->fail('Truncated XML should be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('OSM XML ended before', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($files['output']);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_source_hash_mismatch_invalid_coordinates_and_restriction_relations_are_not_misreported_as_ready(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
            '<relation id="500"><member type="way" ref="100" role="from"/><tag k="type" v="restriction"/><tag k="restriction" v="no_left_turn"/></relation>',
        ]));

        try {
            $badSource = $files['source_meta'];
            $badSource['sha256'] = str_repeat('0', 64);
            try {
                app(OfflinePedestrianGraphStager::class)->build($files['xml'], $files['output'], $files['source'], $badSource);
                $this->fail('A source fingerprint mismatch should be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('does not match the expected SHA-256', $exception->getMessage());
            }
            $result = $this->build($files);
            $this->assertSame('WARN', $result['status']);
            $this->assertFalse($result['routing_ready']);
            $this->assertSame(1, $result['relation_restrictions']);
            try {
                app(OfflinePedestrianGraphValidator::class)->validate($files['output'], $files['source'], str_repeat('0', 64));
                $this->fail('A graph must reject validation against a different source checksum.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('fingerprint does not match', $exception->getMessage());
            }
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_offline_artifact_rejects_post_build_row_tampering(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            $this->build($files);
            $sqlite = new PDO('sqlite:'.$files['output']);
            $sqlite->exec('UPDATE graph_edges SET length_meters=length_meters+1');
            try {
                app(OfflinePedestrianGraphValidator::class)->validate($files['output'], $files['source']);
                $this->fail('Modified edge rows should invalidate the graph content fingerprint.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('content fingerprint', $exception->getMessage());
            }
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_invalid_coordinates_and_duplicate_osm_ids_are_rejected_without_publication(): void
    {
        $fixtures = [
            [
                '<node id="1" lat="91" lon="123.8700"/>',
                '<node id="2" lat="10.3001" lon="123.8700"/>',
                '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
            ],
            [
                '<node id="1" lat="10.3000" lon="123.8700"/>',
                '<node id="1" lat="10.3001" lon="123.8700"/>',
                '<node id="2" lat="10.3002" lon="123.8700"/>',
                '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
            ],
        ];

        foreach ($fixtures as $elements) {
            $files = $this->fixtureFiles();
            file_put_contents($files['xml'], $this->xml($elements));
            try {
                try {
                    $this->build($files);
                    $this->fail('Invalid coordinates or duplicate IDs must stop graph preparation.');
                } catch (\InvalidArgumentException $exception) {
                    $this->assertTrue(
                        str_contains($exception->getMessage(), 'valid WGS84 coordinates')
                            || str_contains($exception->getMessage(), 'Duplicate OSM node ID'),
                        $exception->getMessage(),
                    );
                }
                $this->assertFileDoesNotExist($files['output']);
            } finally {
                $this->removeFixtureFiles($files);
            }
        }
    }

    private function build(array $files): array
    {
        return app(OfflinePedestrianGraphStager::class)->build($files['xml'], $files['output'], $files['source'], $files['source_meta']);
    }

    private function fixtureFiles(): array
    {
        $directory = sys_get_temp_dir().'/biyahe-offline-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $source = $directory.'/central_visayas.osm.pbf';
        file_put_contents($source, 'synthetic verified source bytes');

        return [
            'directory' => $directory,
            'source' => $source,
            'xml' => $directory.'/source.osm',
            'output' => $directory.'/graph.sqlite',
            'source_meta' => [
                'url' => 'https://synthetic.invalid/central_visayas.osm.pbf',
                'sha256' => hash_file('sha256', $source),
                'timestamp' => '2026-10-07T01:00:21Z',
                'osmium_version' => '1.19.1',
            ],
        ];
    }

    private function xml(array $elements): string
    {
        return '<?xml version="1.0"?><osm version="0.6" timestamp="2026-10-07T01:00:21Z">'.implode('', $elements).'</osm>';
    }

    private function removeFixtureFiles(array $files): void
    {
        foreach (glob($files['directory'].'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($files['directory']);
    }
}
