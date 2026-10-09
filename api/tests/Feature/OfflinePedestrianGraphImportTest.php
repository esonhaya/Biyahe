<?php

namespace Tests\Feature;

use App\Enums\PedestrianRoutePurpose;
use App\Enums\PedestrianRouteStatus;
use App\Services\Routing\LocalPedestrianGraphRouter;
use App\Services\Routing\OfflinePedestrianGraphStager;
use App\Services\Routing\PedestrianNetworkImporter;
use App\Services\Routing\PedestrianRouteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OfflinePedestrianGraphImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_validated_offline_graph_is_staged_inactive_and_idempotently_reused(): void
    {
        $files = $this->graphFixture();
        try {
            $importer = app(PedestrianNetworkImporter::class);
            $first = $importer->importOfflineSqlite($files['output'], $files['source'], DB::connection()->getDatabaseName());
            $this->assertFalse($first->alreadyImported);
            $this->assertSame(2, $first->nodes);
            $this->assertSame(1, $first->edges);
            $this->assertSame(0, DB::table('pedestrian_network_datasets')->where('is_active', true)->count());
            $this->assertSame(1, DB::table('pedestrian_network_datasets')->count());
            $this->assertSame(0, DB::table('field_observations')->count());
            $datasetMetadata = json_decode((string) DB::table('pedestrian_network_datasets')->value('metadata'), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('offline_scope_first_sqlite_v3', $datasetMetadata['source_details']['extract_strategy']);
            $this->assertSame(hash_file('sha256', $files['source']), $datasetMetadata['source_details']['source_checksum_sha256']);
            $route = app(LocalPedestrianGraphRouter::class)->route(new PedestrianRouteRequest(
                ['lat' => 10.3, 'lng' => 123.87],
                ['lat' => 10.3001, 'lng' => 123.87],
                PedestrianRoutePurpose::ACCESS_WALK,
                500,
            ));
            $this->assertSame(PedestrianRouteStatus::UNAVAILABLE, $route->status);
            $this->assertNull($route->geometry);

            $second = $importer->importOfflineSqlite($files['output'], $files['source'], DB::connection()->getDatabaseName());
            $this->assertTrue($second->alreadyImported);
            $this->assertSame(1, DB::table('pedestrian_network_datasets')->count());
            $this->assertSame(0, DB::table('pedestrian_network_datasets')->where('is_active', true)->count());
        } finally {
            $this->cleanup($files);
        }
    }

    public function test_idempotent_reuse_rejects_existing_dataset_with_unsafe_turn_capabilities(): void
    {
        $files = $this->graphFixture();
        try {
            $importer = app(PedestrianNetworkImporter::class);
            $importer->importOfflineSqlite($files['output'], $files['source'], DB::connection()->getDatabaseName());
            $metadata = json_decode((string) DB::table('pedestrian_network_datasets')->value('metadata'), true, 32, JSON_THROW_ON_ERROR);
            $metadata['routing_capabilities']['turn_restrictions_needed'] = true;
            DB::table('pedestrian_network_datasets')->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);

            try {
                $importer->importOfflineSqlite($files['output'], $files['source'], DB::connection()->getDatabaseName());
                $this->fail('An existing dataset with unsafe turn capability metadata must not be reused.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('safe inactive restriction-audit staging metadata', $exception->getMessage());
            }

            $this->assertSame(0, DB::table('pedestrian_network_datasets')->where('is_active', true)->count());
        } finally {
            $this->cleanup($files);
        }
    }

    public function test_failed_offline_database_batch_rolls_back_every_staged_row(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The staged-import rollback fixture uses an isolated SQLite trigger.');
        }
        $files = $this->graphFixture();
        $trigger = 'offline_graph_test_corrupt_edge';
        try {
            DB::unprepared("CREATE TRIGGER {$trigger} AFTER INSERT ON pedestrian_network_edges BEGIN UPDATE pedestrian_network_edges SET length_meters=0 WHERE id=NEW.id; END");
            try {
                app(PedestrianNetworkImporter::class)->importOfflineSqlite($files['output'], $files['source'], DB::connection()->getDatabaseName());
                $this->fail('A corrupt staged edge must abort the import transaction.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('validation failed before activation', $exception->getMessage());
            }
            $this->assertSame(0, DB::table('pedestrian_network_datasets')->count());
            $this->assertSame(0, DB::table('pedestrian_network_nodes')->count());
            $this->assertSame(0, DB::table('pedestrian_network_edges')->count());
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            $this->cleanup($files);
        }
    }

    public function test_protected_database_identity_is_rejected_before_file_or_database_work(): void
    {
        try {
            app(PedestrianNetworkImporter::class)->importOfflineSqlite('/no/such/offline.sqlite', '/no/such/source.pbf', 'production');
            $this->fail('Production-named database identity must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('production-named databases are protected', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('pedestrian_network_datasets')->count());
    }

    public function test_incomplete_offline_checkpoint_is_rejected_before_database_staging(): void
    {
        $files = $this->graphFixture();
        $incomplete = $files['directory'].'/incomplete.sqlite.building';
        copy($files['output'], $incomplete);
        $sqlite = new \PDO('sqlite:'.$incomplete, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $sqlite->exec("UPDATE metadata SET value='\"SOURCE_COMPLETE\"' WHERE key='state'");
        $sqlite->exec("UPDATE metadata SET value='false' WHERE key='input_complete'");
        $sqlite->exec("UPDATE metadata SET value='false' WHERE key='references_complete'");

        try {
            try {
                app(PedestrianNetworkImporter::class)->importOfflineSqlite($incomplete, $files['source'], DB::connection()->getDatabaseName());
                $this->fail('An incomplete .building graph must be rejected by the offline importer.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('incomplete or uses an unsupported format', $exception->getMessage());
            }
            $this->assertSame(0, DB::table('pedestrian_network_datasets')->count());
            $this->assertSame(0, DB::table('pedestrian_network_nodes')->count());
            $this->assertSame(0, DB::table('pedestrian_network_edges')->count());
        } finally {
            $this->cleanup($files);
        }
    }

    public function test_applicable_foot_turn_restriction_is_rejected_before_database_staging(): void
    {
        $files = $this->graphFixture();
        $files['xml'] = $files['directory'].'/restricted.osm';
        $files['output'] = $files['directory'].'/restricted.sqlite';
        file_put_contents($files['xml'], '<?xml version="1.0"?><osm version="0.6" timestamp="2026-10-07T01:00:21Z">'
            .'<node id="1" lat="10.3000" lon="123.8700"/><node id="2" lat="10.3001" lon="123.8700"/>'
            .'<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way>'
            .'<relation id="501"><member type="way" ref="100" role="from"/><member type="node" ref="1" role="via"/><member type="way" ref="100" role="to"/><tag k="type" v="restriction"/><tag k="restriction:foot" v="no_u_turn"/></relation></osm>');
        app(OfflinePedestrianGraphStager::class)->build($files['xml'], $files['output'], $files['source'], [
            'url' => 'https://synthetic.invalid/central_visayas.osm.pbf',
            'sha256' => hash_file('sha256', $files['source']),
            'timestamp' => '2026-10-07T01:00:21Z',
            'osmium_version' => '1.19.1',
        ]);

        try {
            try {
                app(PedestrianNetworkImporter::class)->importOfflineSqlite($files['output'], $files['source'], DB::connection()->getDatabaseName());
                $this->fail('An unsupported explicit pedestrian restriction must not be staged.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('restriction inventory is incomplete', $exception->getMessage());
            }
            $this->assertSame(0, DB::table('pedestrian_network_datasets')->count());
            $this->assertSame(0, DB::table('pedestrian_network_nodes')->count());
            $this->assertSame(0, DB::table('pedestrian_network_edges')->count());
        } finally {
            $this->cleanup($files);
        }
    }

    private function graphFixture(): array
    {
        $directory = sys_get_temp_dir().'/biyahe-offline-import-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $source = $directory.'/central_visayas.osm.pbf';
        $xml = $directory.'/source.osm';
        $output = $directory.'/graph.sqlite';
        file_put_contents($source, 'synthetic verified source');
        file_put_contents($xml, '<?xml version="1.0"?><osm version="0.6" timestamp="2026-10-07T01:00:21Z">'
            .'<node id="1" lat="10.3000" lon="123.8700"/><node id="2" lat="10.3001" lon="123.8700"/>'
            .'<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way></osm>');
        app(OfflinePedestrianGraphStager::class)->build($xml, $output, $source, [
            'url' => 'https://synthetic.invalid/central_visayas.osm.pbf',
            'sha256' => hash_file('sha256', $source),
            'timestamp' => '2026-10-07T01:00:21Z',
            'osmium_version' => '1.19.1',
        ]);

        return compact('directory', 'source', 'xml', 'output');
    }

    private function cleanup(array $files): void
    {
        foreach (glob($files['directory'].'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($files['directory']);
    }
}
