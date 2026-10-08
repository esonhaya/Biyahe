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

    public function test_conditionally_restricted_pedestrian_ways_are_excluded_from_static_graph(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<node id="3" lat="10.3010" lon="123.8700"/>',
            '<node id="4" lat="10.3011" lon="123.8700"/>',
            '<node id="5" lat="10.3020" lon="123.8700"/>',
            '<node id="6" lat="10.3021" lon="123.8700"/>',
            '<node id="7" lat="10.3030" lon="123.8700"/>',
            '<node id="8" lat="10.3031" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
            '<way id="200"><nd ref="3"/><nd ref="4"/><tag k="highway" v="footway"/><tag k="foot:forward:conditional" v="no @ (Mo-Fr)"/></way>',
            '<way id="300"><nd ref="5"/><nd ref="6"/><tag k="highway" v="footway"/><tag k="access:backward:conditional" v="no @ (Mo-Fr)"/></way>',
            '<way id="400"><nd ref="7"/><nd ref="8"/><tag k="highway" v="footway"/><tag k="oneway:foot:conditional" v="yes @ (Mo-Fr)"/></way>',
        ]));

        try {
            $result = $this->build($files);
            $this->assertSame('PASS', $result['status']);
            $sqlite = new PDO('sqlite:'.$files['output']);
            $this->assertSame(1, (int) $sqlite->query('SELECT COUNT(*) FROM source_ways')->fetchColumn());
            $this->assertSame([100], array_map('intval', $sqlite->query('SELECT DISTINCT osm_way_id FROM graph_edges')->fetchAll(PDO::FETCH_COLUMN)));
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_offline_scope_and_pedestrian_selection_are_separate_from_legacy_input_cap(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<node id="3" lat="10.3002" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
            '<way id="200"><nd ref="1"/><nd ref="3"/><tag k="highway" v="motorway"/></way>',
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
            $sqlite = new PDO('sqlite:'.$files['output']);
            $this->assertSame(1, (int) $sqlite->query('SELECT COUNT(*) FROM source_ways')->fetchColumn());

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

    public function test_candidate_ways_keep_boundary_references_and_resolve_only_needed_out_of_scope_nodes(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="10" lat="10.2400" lon="123.8700"/>',
            '<node id="11" lat="10.3000" lon="123.8700"/>',
            '<node id="12" lat="10.3001" lon="123.8700"/>',
            '<node id="13" lat="10.3900" lon="123.8700"/>',
            '<way id="500"><nd ref="10"/><nd ref="11"/><nd ref="12"/><nd ref="13"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            $result = $this->build($files);
            $this->assertSame('PASS', $result['status']);
            $sqlite = new PDO('sqlite:'.$files['output']);
            $this->assertSame(4, (int) $sqlite->query('SELECT COUNT(*) FROM way_refs WHERE way_id=500')->fetchColumn());
            $this->assertSame(2, (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes WHERE in_scope=1')->fetchColumn());
            $this->assertSame(2, (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes WHERE in_scope=0')->fetchColumn());
            $this->assertSame(1, (int) $sqlite->query('SELECT COUNT(*) FROM graph_edges WHERE osm_way_id=500')->fetchColumn());
            $this->assertSame(4, (int) json_decode((string) $sqlite->query("SELECT value FROM metadata WHERE key='resolved_nodes'")->fetchColumn(), true));
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_candidate_selection_does_not_depend_on_nodes_appearing_before_ways(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<way id="500"><nd ref="10"/><nd ref="11"/><tag k="highway" v="footway"/></way>',
            '<node id="10" lat="10.3000" lon="123.8700"/>',
            '<node id="11" lat="10.3001" lon="123.8700"/>',
        ]));

        try {
            $result = $this->build($files);

            $this->assertSame('PASS', $result['status']);
            $this->assertSame(1, $result['edges']);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_crossing_geometry_without_a_shared_osm_node_stays_disconnected(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8699"/>',
            '<node id="2" lat="10.3002" lon="123.8701"/>',
            '<node id="3" lat="10.2999" lon="123.8700"/>',
            '<node id="4" lat="10.3003" lon="123.8700"/>',
            '<way id="600"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
            '<way id="700"><nd ref="3"/><nd ref="4"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            $result = $this->build($files);

            $this->assertSame(2, $result['components']);
            $this->assertSame(2, $result['edges']);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_incomplete_reference_resolution_can_restart_without_rebuilding_the_way_index(): void
    {
        $files = $this->fixtureFiles();
        $completeXml = $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<node id="3" lat="10.3900" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><nd ref="3"/><tag k="highway" v="footway"/></way>',
        ]);
        file_put_contents($files['xml'], $completeXml);

        try {
            $stager = app(OfflinePedestrianGraphStager::class);
            $stager->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], false, true, true);
            $idsPath = $files['directory'].'/required-node-ids.txt';
            $index = $stager->build('-', $files['output'], $files['source'], $files['source_meta'], true, true, false, true, false, $idsPath);
            $this->assertSame('WAY_INDEX_COMPLETE', $index['state']);

            file_put_contents($files['xml'], $this->xml([
                '<node id="1" lat="10.3000" lon="123.8700"/>',
                '<node id="2" lat="10.3001" lon="123.8700"/>',
            ]));
            try {
                $stager->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], true, false, false, false, true);
                $this->fail('A node lookup that omitted a requested source node must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('missing from the source stream', $exception->getMessage());
            }

            file_put_contents($files['xml'], $completeXml);
            $result = $stager->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], true, false, false, false, true);
            $this->assertSame('PASS', $result['status']);
            $sqlite = new PDO('sqlite:'.$files['output']);
            $this->assertSame(1, (int) $sqlite->query('SELECT COUNT(*) FROM source_ways WHERE id=100')->fetchColumn());
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

    public function test_completed_source_phases_store_provenance_counts_and_output_fingerprints(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            app(OfflinePedestrianGraphStager::class)->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], false, true);
            $sqlite = new PDO('sqlite:'.$files['output'].'.building');
            $phases = $sqlite->query('SELECT phase, source_sha256, source_snapshot, schema_version, completion_state, record_counts, output_fingerprint FROM phase_checkpoints ORDER BY phase')->fetchAll(PDO::FETCH_ASSOC);

            $this->assertSame(['reference_resolution', 'way_index'], array_column($phases, 'phase'));
            foreach ($phases as $phase) {
                $this->assertSame($files['source_meta']['sha256'], $phase['source_sha256']);
                $this->assertSame('2026-10-07T01:00:21+00:00', $phase['source_snapshot']);
                $this->assertSame(3, (int) $phase['schema_version']);
                $this->assertSame('COMPLETE', $phase['completion_state']);
                $this->assertIsArray(json_decode((string) $phase['record_counts'], true));
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $phase['output_fingerprint']);
            }
            $this->assertFileDoesNotExist($files['output']);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_resume_rejects_a_corrupted_phase_fingerprint(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            app(OfflinePedestrianGraphStager::class)->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], false, true);
            $sqlite = new PDO('sqlite:'.$files['output'].'.building');
            $statement = $sqlite->prepare("UPDATE phase_checkpoints SET output_fingerprint=? WHERE phase='way_index'");
            $statement->execute([str_repeat('0', 64)]);

            try {
                app(OfflinePedestrianGraphStager::class)->build('-', $files['output'], $files['source'], $files['source_meta'], true, true);
                $this->fail('A source checkpoint with a changed output fingerprint must not be reused.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('output fingerprint', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($files['output']);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_resume_rejects_a_checkpoint_schema_mismatch(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            app(OfflinePedestrianGraphStager::class)->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], false, true);
            $sqlite = new PDO('sqlite:'.$files['output'].'.building');
            $sqlite->exec("UPDATE metadata SET value='99' WHERE key='schema_version'");

            try {
                app(OfflinePedestrianGraphStager::class)->build('-', $files['output'], $files['source'], $files['source_meta'], true, true);
                $this->fail('A staging checkpoint with a changed schema version must not be reused.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('schema version', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($files['output']);
        } finally {
            $this->removeFixtureFiles($files);
        }
    }

    public function test_verified_v2_way_index_checkpoints_migrate_without_replaying_the_source_pass(): void
    {
        $files = $this->fixtureFiles();
        file_put_contents($files['xml'], $this->xml([
            '<node id="1" lat="10.3000" lon="123.8700"/>',
            '<node id="2" lat="10.3001" lon="123.8700"/>',
            '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
        ]));

        try {
            app(OfflinePedestrianGraphStager::class)->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], false, true);
            $sqlite = new PDO('sqlite:'.$files['output'].'.building');
            $sqlite->exec("UPDATE metadata SET value='\"biyahe-pedestrian-sqlite-v2\"' WHERE key='format'");
            $sqlite->exec("UPDATE metadata SET value='2' WHERE key='schema_version'");
            $sqlite->exec("UPDATE metadata SET value='\"scope_first_way_index_then_osmium_getid\"' WHERE key='extract_strategy'");
            $sqlite->exec('DROP TABLE phase_checkpoints');

            $result = app(OfflinePedestrianGraphStager::class)->build('-', $files['output'], $files['source'], $files['source_meta'], true, true);
            $metadata = new PDO('sqlite:'.$files['output'].'.building');

            $this->assertSame('SOURCE_COMPLETE', $result['state']);
            $this->assertSame('"biyahe-pedestrian-sqlite-v3"', $metadata->query("SELECT value FROM metadata WHERE key='format'")->fetchColumn());
            $this->assertSame('3', $metadata->query("SELECT value FROM metadata WHERE key='schema_version'")->fetchColumn());
            $this->assertSame(2, (int) $metadata->query('SELECT COUNT(*) FROM phase_checkpoints')->fetchColumn());
            $this->assertSame('offline_scope_first_sqlite_v3', json_decode((string) $metadata->query("SELECT value FROM metadata WHERE key='extract_strategy'")->fetchColumn(), true));
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
            $this->assertSame('SOURCE_PASS_PENDING_PRODUCER', $result['state']);
            try {
                $stager->build('-', $files['output'], $files['source'], $files['source_meta'], true);
                $this->fail('A pipeline checkpoint must wait for the producer exit result.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('has not reported a clean exit', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($files['output']);
            $idsPath = $files['directory'].'/required-node-ids.txt';
            $result = $stager->build('-', $files['output'], $files['source'], $files['source_meta'], true, true, false, true, false, $idsPath);
            $this->assertSame('WAY_INDEX_COMPLETE', $result['state']);
            $this->assertSame("n1\nn2\n", file_get_contents($idsPath));
            $result = $stager->build($files['xml'], $files['output'], $files['source'], $files['source_meta'], true, true, true, false, true);
            $this->assertSame('RESOLUTION_PENDING_PRODUCER', $result['state']);
            try {
                $stager->build('-', $files['output'], $files['source'], $files['source_meta'], true);
                $this->fail('A reference pass must also wait for a clean producer exit.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('has not reported a clean exit', $exception->getMessage());
            }
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
            $sqlite = new PDO('sqlite:'.$files['output']);
            $restriction = $sqlite->query('SELECT relation_id, member_count, members_sha256 FROM source_restrictions')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(500, (int) $restriction['relation_id']);
            $this->assertSame(1, (int) $restriction['member_count']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $restriction['members_sha256']);
            $this->assertSame([
                ['relation_id' => 500, 'sequence_index' => 0, 'member_type' => 'way', 'member_ref' => 100, 'role' => 'from'],
            ], $sqlite->query('SELECT relation_id, sequence_index, member_type, member_ref, role FROM source_restriction_members')->fetchAll(PDO::FETCH_ASSOC));
            try {
                app(OfflinePedestrianGraphValidator::class)->validate($files['output'], $files['source'], str_repeat('0', 64));
                $this->fail('A graph must reject validation against a different source checksum.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('fingerprint does not match', $exception->getMessage());
            }
            $sqlite->exec("UPDATE source_restriction_members SET role='to'");
            try {
                app(OfflinePedestrianGraphValidator::class)->validate($files['output'], $files['source']);
                $this->fail('Modified restriction members must invalidate the source phase checkpoint.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('restriction dependency fingerprint', $exception->getMessage());
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
            [
                '<node id="1" lat="10.3000" lon="123.8700"/>',
                '<node id="2" lat="10.3001" lon="123.8700"/>',
                '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
                '<way id="100"><nd ref="2"/><nd ref="1"/><tag k="highway" v="footway"/></way>',
            ],
            [
                '<node id="1" lat="10.3000" lon="123.8700"/>',
                '<node id="2" lat="10.3001" lon="123.8700"/>',
                '<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>',
                '<relation id="500"><tag k="type" v="route"/></relation>',
                '<relation id="500"><tag k="type" v="route"/></relation>',
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
                            || str_contains($exception->getMessage(), 'Duplicate OSM node ID')
                            || str_contains($exception->getMessage(), 'Duplicate OSM way ID')
                            || str_contains($exception->getMessage(), 'Duplicate OSM relation ID'),
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
