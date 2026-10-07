<?php

namespace Tests\Feature;

use App\Services\FieldSurveys\Batch019GroundTruthCampaign;
use App\Services\Routing\PedestrianNetworkImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Batch019GroundTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_ground_truth_campaign_is_derived_and_non_promoting(): void
    {
        $payload = app(Batch019GroundTruthCampaign::class)->build([
            'network_fingerprint' => 'synthetic-fingerprint',
            'results' => [],
        ], 100);

        $this->assertSame(Batch019GroundTruthCampaign::VERSION, $payload['campaign_version']);
        $this->assertSame(0, $payload['claim_count']);
        $this->assertSame(0, $payload['mission_count']);
        $this->assertSame(0, $payload['promotion_gate']['automatic_promotions']);
        $this->assertCount(3, $payload['source_review']);
    }

    public function test_incremental_graph_merge_preserves_base_coordinates_and_skips_conflicting_edges(): void
    {
        $basePath = $this->writeExtract([
            ['type' => 'node', 'id' => 1, 'lat' => 10.3000000, 'lon' => 123.8700000, 'tags' => []],
            ['type' => 'node', 'id' => 2, 'lat' => 10.3001000, 'lon' => 123.8700000, 'tags' => []],
            ['type' => 'way', 'id' => 100, 'nodes' => [1, 2], 'tags' => ['highway' => 'footway']],
        ]);
        $updatePath = $this->writeExtract([
            ['type' => 'node', 'id' => 1, 'lat' => 10.3002000, 'lon' => 123.8700000, 'tags' => []],
            ['type' => 'node', 'id' => 2, 'lat' => 10.3001000, 'lon' => 123.8700000, 'tags' => []],
            ['type' => 'node', 'id' => 3, 'lat' => 10.3002000, 'lon' => 123.8702000, 'tags' => []],
            ['type' => 'node', 'id' => 4, 'lat' => 10.3003000, 'lon' => 123.8702000, 'tags' => []],
            ['type' => 'way', 'id' => 200, 'nodes' => [3, 4], 'tags' => ['highway' => 'footway']],
            ['type' => 'way', 'id' => 201, 'nodes' => [1, 3], 'tags' => ['highway' => 'footway']],
        ], [
            'format' => 'osmium-osm-xml-v1',
            'source' => 'OPENSTREETMAP',
            'source_url' => 'https://synthetic.invalid/b019-update.json',
            'source_file' => 'b019-update.osm.pbf',
            'source_checksum_md5' => '0123456789abcdef0123456789abcdef',
            'source_timestamp' => '2026-10-07T00:00:00Z',
            'source_elements' => 6,
            'osmium_version' => '1.0.0',
            'extract_strategy' => 'complete_ways',
            'extract_bounds' => config('biyahe.pedestrian.coverage'),
            'license' => 'ODbL-1.0',
            'attribution' => '© OpenStreetMap contributors',
        ]);

        try {
            app(PedestrianNetworkImporter::class)->import($basePath, 'https://synthetic.invalid/b019-base.json');
            app(PedestrianNetworkImporter::class)->import($updatePath, 'https://synthetic.invalid/b019-update.json', true);

            $activeDataset = DB::table('pedestrian_network_datasets')->where('is_active', true)->first();
            $metadata = json_decode((string) $activeDataset->metadata, true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(4, DB::table('pedestrian_network_nodes')->where('dataset_id', $activeDataset->id)->count());
            $this->assertSame(2, DB::table('pedestrian_network_edges')->where('dataset_id', $activeDataset->id)->count());
            $this->assertEqualsWithDelta(10.3000000, (float) DB::table('pedestrian_network_nodes')
                ->where('dataset_id', $activeDataset->id)->where('osm_node_id', 1)->value('latitude'), 0.0000001);
            $this->assertSame(1, $metadata['import_metrics']['merge_skipped_conflicting_nodes']);
            $this->assertSame(1, $metadata['import_metrics']['merge_skipped_conflicting_edges']);
            $this->assertSame(0, DB::table('field_observations')->count());

            $activeStableId = $activeDataset->stable_id;
            $again = app(PedestrianNetworkImporter::class)->import($updatePath, 'https://synthetic.invalid/b019-update.json', true);
            $this->assertTrue($again->alreadyImported);
            $this->assertSame($activeStableId, $again->stableId);
            $this->assertSame(1, DB::table('pedestrian_network_datasets')->where('is_active', true)->count());
        } finally {
            @unlink($basePath);
            @unlink($updatePath);
        }
    }

    /** @param list<array<string, mixed>> $elements @param array<string, mixed> $source */
    private function writeExtract(array $elements, array $source = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'batch019-pedestrian-');
        if ($path === false) {
            $this->fail('Could not create synthetic Batch 019 extract.');
        }
        $document = ['elements' => $elements];
        if ($source !== []) {
            $document['biyahe_source'] = $source;
        }
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

        return $path;
    }
}
