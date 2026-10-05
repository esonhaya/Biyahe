<?php

namespace Tests\Feature;

use App\Enums\VehicleTypeCode;
use App\Models\Place;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Models\VehicleType;
use App\Services\Data\DatasetImporter;
use App\Services\Data\DatasetValidator;
use App\Services\Evidence\EvidenceCoverageReport;
use App\Services\Evidence\EvidenceJourneyPlanCache;
use App\Services\Evidence\EvidencePriorityEngine;
use App\Services\Routing\JourneyPlanner;
use App\Support\Trust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EvidenceCoverageReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_accounts_for_each_imported_production_claim(): void
    {
        $this->importCentralDataset();
        $data = json_decode((string) file_get_contents($this->datasetPath()), true, 512, JSON_THROW_ON_ERROR);

        $report = app(EvidenceCoverageReport::class)->report(false, true);

        $this->assertSame(count($data['places']), $report['places_total']);
        $this->assertSame(count($data['routes']), $report['routes_total']);
        $this->assertSame(count($data['patterns']), $report['patterns_total']);
        $this->assertSame(array_sum(array_map(fn (array $pattern): int => count($pattern['stops']), $data['patterns'])), $report['pattern_stops_total']);
        $this->assertCount(count($data['routes']), $report['fact_audit']['routes']);
        $this->assertSame(['service', 'sequence', 'boarding', 'alighting'], array_keys($report['fact_audit']['pattern_stops'][0]['facts']));
        $this->assertNotEmpty($report['provenance_audit']);
        $this->assertSame(1, $report['freshness']['PLACE_LOCATION']['CURRENT']);
    }

    public function test_priority_analysis_respects_requested_pair_and_transfer_bounds(): void
    {
        $this->importCentralDataset();

        $result = app(EvidencePriorityEngine::class)->analyze(limit: 5, maxPairs: 1, maxTransfers: 0);

        $this->assertSame(1, $result['pairs_examined']);
        $this->assertSame([], $result['errors']);
        foreach ($result['items'] as $index => $item) {
            $this->assertSame($index + 1, $item['rank']);
            $this->assertArrayHasKey('record_id', $item);
            $this->assertArrayHasKey('affected_od_pairs', $item);
        }
    }

    public function test_reported_current_fare_rules_are_general_unverified_and_not_route_specific(): void
    {
        $path = database_path('data/cebu-network-expansion-2026-10-05.json');
        $validation = app(DatasetValidator::class)->validate($path);
        $this->assertTrue($validation->isValid(), json_encode($validation->errors()));
        $dataset = $validation->data;
        $rules = collect($dataset['fares'])->where('effective_from', '2026-09-28');

        $this->assertCount(4, $rules);
        foreach ($rules as $rule) {
            $this->assertSame('VEHICLE_CLASS', $rule['applicability_scope']);
            $this->assertNull($rule['route_id']);
            $this->assertSame('UNVERIFIED', $rule['verification_state']);
            $this->assertSame('2026-12-31', $rule['effective_until']);
            $notes = strtolower($rule['notes']);
            $this->assertTrue(
                str_contains($notes, 'not used to calculate') || str_contains($notes, 'no total is calculated')
            );
        }
    }

    public function test_institutional_12l_evidence_only_verifies_ayala_boarding_facts(): void
    {
        $this->importCentralDataset();

        $stop = Stop::query()->where('stable_id', 'stop-ayala-center-terminal')->firstOrFail();
        $stop->load('boardingProvenanceSource');
        $this->assertSame('BOARDING_AREA', $stop->boarding_precision->value);
        $this->assertSame('VERIFIED', Trust::effectiveFactState($stop, 'boarding'));

        $association = RoutePatternStop::query()->where('stable_id', 'pattern-12l-forward-stop-01')->firstOrFail();
        $association->load(['serviceProvenanceSource', 'sequenceProvenanceSource', 'boardingProvenanceSource', 'alightingProvenanceSource']);
        $this->assertSame('VERIFIED', Trust::effectiveFactState($association, 'service'));
        $this->assertSame('VERIFIED', Trust::effectiveFactState($association, 'boarding'));
        $this->assertSame('UNVERIFIED', Trust::effectiveFactState($association, 'sequence'));
        $this->assertSame('UNVERIFIED', Trust::effectiveFactState($association, 'alighting'));

        $pattern = RoutePattern::query()->where('stable_id', 'pattern-12l-forward')->firstOrFail();
        $pattern->load('provenanceSource');
        $this->assertSame('UNVERIFIED', Trust::effectiveState($pattern));

        $report = app(EvidenceCoverageReport::class)->report(false, false);
        $this->assertSame(1, $report['pattern_stops_service_verified']);
        $this->assertSame(1, $report['pattern_stops_boarding_verified']);
        $this->assertSame(1, $report['boarding_points_area']);
    }

    public function test_evidence_plan_cache_reuses_duplicate_origin_destination_searches(): void
    {
        $this->importCentralDataset();
        $origin = Place::query()
            ->when(config('database.default') === 'pgsql', fn ($builder) => $builder->addSelect([
                'places.*',
                DB::raw('ST_AsText(places.location) AS location_wkt'),
            ]))
            ->where('slug', 'ayala-center-cebu')
            ->firstOrFail();
        $destination = Place::query()
            ->when(config('database.default') === 'pgsql', fn ($builder) => $builder->addSelect([
                'places.*',
                DB::raw('ST_AsText(places.location) AS location_wkt'),
            ]))
            ->where('slug', 'labangon')
            ->firstOrFail();
        $uncached = app(JourneyPlanner::class)->plan($origin, $destination, maxTransfers: 0);
        $cache = app(EvidenceJourneyPlanCache::class);

        $first = $cache->plan($origin, $destination, maxTransfers: 0);
        $second = $cache->plan($origin, $destination, maxTransfers: 0);

        $this->assertSame($first, $second);
        $this->assertNotEmpty($first->journeys);
        $journeySignature = fn ($journey): array => [
            $journey->id,
            $journey->trustState->value,
            array_map(fn ($leg): array => [
                $leg->type->value,
                $leg->pattern['id'] ?? null,
                $leg->boardingStop['id'] ?? null,
                $leg->alightingStop['id'] ?? null,
                $leg->boardingSequence,
                $leg->alightingSequence,
            ], $journey->legs),
        ];
        $this->assertSame(
            array_map($journeySignature, $uncached->journeys),
            array_map($journeySignature, $first->journeys),
        );
        $this->assertSame(1, $cache->metrics()['planner_runs']);
        $this->assertSame(1, $cache->metrics()['result_cache_hits']);
        $this->assertGreaterThan(0, $cache->metrics()['trust_cache_hits']);
    }

    public function test_retired_fare_rules_are_not_double_counted_as_stale(): void
    {
        $this->importCentralDataset();
        $validation = app(DatasetValidator::class)->validate(database_path('data/cebu-network-expansion-2026-10-05.json'));
        $this->assertTrue($validation->isValid(), json_encode($validation->errors()));
        app(DatasetImporter::class)->import($validation->data ?? []);

        $report = app(EvidenceCoverageReport::class)->report(false, false);

        $this->assertSame(4, $report['fare_rules_stale']);
        $this->assertSame(12, $report['fare_rules_retired']);
    }

    private function importCentralDataset(): void
    {
        foreach (VehicleTypeCode::cases() as $vehicleType) {
            VehicleType::create([
                'code' => $vehicleType->value,
                'name' => $vehicleType->value,
                'is_active' => true,
            ]);
        }
        $validation = app(DatasetValidator::class)->validate($this->datasetPath());
        $this->assertTrue($validation->isValid(), json_encode($validation->errors()));
        app(DatasetImporter::class)->import($validation->data ?? []);
    }

    private function datasetPath(): string
    {
        return database_path('data/cebu-central-network-2026-10-05.json');
    }
}
