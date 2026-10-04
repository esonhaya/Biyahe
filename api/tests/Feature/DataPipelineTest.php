<?php

namespace Tests\Feature;

use App\Enums\VehicleTypeCode;
use App\Models\ProvenanceSource;
use App\Models\VehicleType;
use App\Services\Data\DatasetImporter;
use App\Services\Data\DatasetValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DataPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewed_dataset_validates(): void
    {
        $result = app(DatasetValidator::class)->validate($this->datasetPath());

        $this->assertTrue($result->isValid(), json_encode($result->errors()));
        $this->assertGreaterThan(30, $result->recordCount());
    }

    public function test_dry_run_does_not_write_records(): void
    {
        $this->artisan('biyahe:data:import', [
            'dataset' => $this->datasetPath(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertDatabaseCount('provenance_sources', 0);
        $this->assertDatabaseCount('routes', 0);
    }

    public function test_import_is_transactional_and_repeatable(): void
    {
        foreach (VehicleTypeCode::cases() as $vehicleType) {
            VehicleType::create([
                'code' => $vehicleType->value,
                'name' => $vehicleType->value,
                'is_active' => true,
            ]);
        }

        $result = app(DatasetValidator::class)->validate($this->datasetPath());
        $first = app(DatasetImporter::class)->import($result->data ?? []);
        $second = app(DatasetImporter::class)->import($result->data ?? []);

        $this->assertGreaterThan(0, $first->total('imported'));
        $this->assertSame(0, $second->total('imported'));
        $this->assertSame(0, $second->total('updated'));
        $this->assertGreaterThan(0, $second->total('skipped'));
        $this->assertSame(1, ProvenanceSource::where('stable_id', 'osm-cebu-transport-2026-10-05')->count());
    }

    public function test_invalid_dataset_reports_duplicate_and_unresolved_references(): void
    {
        $data = $this->dataset();
        $data['places'][] = $data['places'][0];
        $data['aliases'][0]['place_id'] = 'missing-place';
        $path = $this->temporaryDataset($data);

        try {
            $result = app(DatasetValidator::class)->validate($path);
            $messages = array_column($result->errors(), 'message');

            $this->assertFalse($result->isValid());
            $this->assertTrue(collect($messages)->contains(fn (string $message): bool => str_contains($message, 'Duplicate stable_id')));
            $this->assertTrue(collect($messages)->contains(fn (string $message): bool => str_contains($message, 'does not resolve')));
        } finally {
            unlink($path);
        }
    }

    public function test_import_rolls_back_when_runtime_dependency_is_missing(): void
    {
        $result = app(DatasetValidator::class)->validate($this->datasetPath());

        $this->assertTrue($result->isValid());
        try {
            app(DatasetImporter::class)->import($result->data ?? []);
            $this->fail('The import should fail when vehicle types are absent.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('provenance_sources', 0);
            $this->assertDatabaseCount('cities', 0);
        }
    }

    public function test_demo_records_cannot_be_marked_verified(): void
    {
        $data = $this->dataset();
        $data['places'][0]['is_demo'] = true;
        $data['places'][0]['verification_state'] = 'VERIFIED';
        $path = $this->temporaryDataset($data);

        try {
            $result = app(DatasetValidator::class)->validate($path);

            $this->assertFalse($result->isValid());
            $this->assertTrue(collect(array_column($result->errors(), 'message'))->contains(fn (string $message): bool => str_contains($message, 'cannot be VERIFIED')));
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function dataset(): array
    {
        return json_decode((string) file_get_contents($this->datasetPath()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function datasetPath(): string
    {
        return database_path('data/cebu-central-network-2026-10-05.json');
    }

    /** @param array<string, mixed> $data */
    private function temporaryDataset(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'biyahe-dataset-');
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        return $path;
    }
}
