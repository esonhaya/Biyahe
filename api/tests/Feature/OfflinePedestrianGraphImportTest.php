<?php

namespace Tests\Feature;

use App\Enums\PedestrianRoutePurpose;
use App\Enums\PedestrianRouteStatus;
use App\Services\Routing\OfflinePedestrianGraphStager;
use App\Services\Routing\LocalPedestrianGraphRouter;
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
