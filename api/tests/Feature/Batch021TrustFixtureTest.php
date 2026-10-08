<?php

namespace Tests\Feature;

use App\Enums\BoardingPrecision;
use App\Enums\JourneyLegType;
use App\Enums\VerificationState;
use App\Models\Place;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Services\Routing\JourneyPlanner;
use App\Support\Trust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An isolated proof that the existing trust engine can reach VERIFIED when
 * every fact used by a direct journey has appropriately scoped evidence.
 * This fixture is intentionally test-only and RefreshDatabase keeps it out
 * of the production transport dataset.
 */
class Batch021TrustFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_supported_direct_fixture_becomes_verified(): void
    {
        $this->seed();
        [$origin, $destination, $direct] = $this->findDirectFixtureJourney();
        $source = $this->completeSource();
        $this->promoteJourneyFacts($origin, $destination, $direct, $source);

        $verified = app(JourneyPlanner::class)->plan($this->loadPlace($origin->slug), $this->loadPlace($destination->slug), maxTransfers: 0);
        $verifiedJourney = collect($verified->journeys)->first(fn ($journey): bool => $journey->trustState->value === 'VERIFIED');

        $this->assertNotNull($verifiedJourney, json_encode(collect($verified->journeys)->map(fn ($journey): array => ['trust' => $journey->trustState->value, 'legs' => array_map(fn ($leg): ?string => $leg->trust?->value, $journey->legs), 'ride' => collect($journey->legs)->filter(fn ($leg): bool => $leg->type === JourneyLegType::RIDE)->map(fn ($leg): array => ['pattern' => $leg->pattern['id'] ?? null, 'boarding' => $leg->boardingStop['id'] ?? null, 'alighting' => $leg->alightingStop['id'] ?? null])->all()])->all()));
        $pattern = RoutePattern::query()->with(['provenanceSource', 'route.provenanceSource'])->findOrFail($direct['pattern_id']);
        $boarding = RoutePatternStop::query()->with(['serviceProvenanceSource', 'sequenceProvenanceSource', 'boardingProvenanceSource', 'alightingProvenanceSource'])->findOrFail($direct['boarding_association_id']);
        $alighting = RoutePatternStop::query()->with(['serviceProvenanceSource', 'sequenceProvenanceSource', 'boardingProvenanceSource', 'alightingProvenanceSource'])->findOrFail($direct['alighting_association_id']);
        $this->assertSame('VERIFIED', $verifiedJourney->trustState->value, json_encode([
            'origin' => Trust::effectiveState($origin->fresh()->load('provenanceSource')),
            'destination' => Trust::effectiveState($destination->fresh()->load('provenanceSource')),
            'pattern' => Trust::effectiveState($pattern),
            'route' => Trust::effectiveState($pattern->route),
            'boarding_stop' => Trust::effectiveFactState(Stop::query()->with(['provenanceSource', 'boardingProvenanceSource'])->findOrFail($direct['boarding_stop_id']), 'boarding'),
            'alighting_stop' => Trust::effectiveFactState(Stop::query()->with(['provenanceSource', 'boardingProvenanceSource'])->findOrFail($direct['alighting_stop_id']), 'boarding'),
            'boarding_facts' => Trust::effectiveFactSetState($boarding, ['service', 'sequence', 'boarding']),
            'alighting_facts' => Trust::effectiveFactSetState($alighting, ['service', 'sequence', 'alighting']),
        ]));
    }

    public function test_incomplete_stale_and_conflicted_variants_do_not_become_verified(): void
    {
        $this->seed();
        [$origin, $destination, $direct] = $this->findDirectFixtureJourney();
        $source = $this->completeSource();
        $this->promoteJourneyFacts($origin, $destination, $direct, $source);

        $association = RoutePatternStop::query()->findOrFail($direct['boarding_association_id']);
        $association->update(['sequence_verification_state' => VerificationState::UNVERIFIED]);
        $this->assertNotSame('VERIFIED', $this->plannedTrust($origin, $destination));

        $association->update(['sequence_verification_state' => VerificationState::VERIFIED]);
        $source->update(['checked_at' => now()->subYears(2)->toDateString()]);
        $this->assertNotSame('VERIFIED', $this->plannedTrust($origin, $destination));

        $source->update(['checked_at' => now()->toDateString(), 'conflict_status' => 'OPEN']);
        $this->assertNotSame('VERIFIED', $this->plannedTrust($origin, $destination));
    }

    /** @return array{0: Place, 1: Place, 2: array{pattern_id: int, boarding_stop_id: int, alighting_stop_id: int, boarding_association_id: int, alighting_association_id: int}} */
    private function findDirectFixtureJourney(): array
    {
        $origin = $this->loadPlace('ayala-center-cebu');
        $destination = $this->loadPlace('carbon-market-unit-3');
        $result = app(JourneyPlanner::class)->plan($origin, $destination, maxTransfers: 0);
        $journey = collect($result->journeys)->first(function ($candidate): bool {
            return collect($candidate->legs)->filter(fn ($leg): bool => $leg->type === JourneyLegType::RIDE)->count() === 1;
        });

        $this->assertNotNull($journey, 'The stable Ayala → Carbon fixture must retain a direct journey.');
        $ride = collect($journey->legs)->first(fn ($leg): bool => $leg->type === JourneyLegType::RIDE);
        $this->assertNotNull($ride);
        $patternId = (int) ($ride->pattern['id'] ?? 0);
        $this->assertGreaterThan(0, $patternId);

        $boardingStopId = (int) ($ride->boardingStop['id'] ?? 0);
        $alightingStopId = (int) ($ride->alightingStop['id'] ?? 0);
        $this->assertGreaterThan(0, $boardingStopId);
        $this->assertGreaterThan(0, $alightingStopId);
        $this->assertNotNull($ride->boardingSequence);
        $this->assertNotNull($ride->alightingSequence);

        $boardingAssociation = RoutePatternStop::query()->where('route_pattern_id', $patternId)->where('sequence', $ride->boardingSequence)->firstOrFail();
        $alightingAssociation = RoutePatternStop::query()->where('route_pattern_id', $patternId)->where('sequence', $ride->alightingSequence)->firstOrFail();

        return [$origin, $destination, [
            'pattern_id' => $patternId,
            'boarding_stop_id' => $boardingStopId,
            'alighting_stop_id' => $alightingStopId,
            'boarding_association_id' => $boardingAssociation->id,
            'alighting_association_id' => $alightingAssociation->id,
        ]];
    }

    private function completeSource(): ProvenanceSource
    {
        return ProvenanceSource::factory()->create([
            'source_type' => 'FIELD_VERIFICATION',
            'title' => 'Batch 021 isolated trust fixture',
            'publisher' => 'Biyahe test harness',
            'checked_at' => now()->toDateString(),
            'observed_at' => now()->toDateString(),
            'conflict_status' => 'NONE',
            'evidence_scope' => [
                'PLACE_LOCATION', 'STOP_LOCATION', 'ROUTE_IDENTITY', 'ROUTE_PATTERN',
                'BOARDING', 'PATTERN_STOP_SERVICE', 'PATTERN_STOP_SEQUENCE',
                'PATTERN_STOP_BOARDING', 'PATTERN_STOP_ALIGHTING',
            ],
        ]);
    }

    /** @param array{pattern_id: int, boarding_stop_id: int, alighting_stop_id: int, boarding_association_id: int, alighting_association_id: int} $direct */
    private function promoteJourneyFacts(Place $origin, Place $destination, array $direct, ProvenanceSource $source): void
    {
        $origin->update(['verification_state' => VerificationState::VERIFIED, 'provenance_source_id' => $source->id, 'is_demo' => false]);
        $destination->update(['verification_state' => VerificationState::VERIFIED, 'provenance_source_id' => $source->id, 'is_demo' => false]);

        $pattern = RoutePattern::query()->findOrFail($direct['pattern_id']);
        $route = Route::query()->findOrFail($pattern->route_id);
        $pattern->update(['verification_state' => VerificationState::VERIFIED, 'provenance_source_id' => $source->id]);
        $route->update(['verification_state' => VerificationState::VERIFIED, 'provenance_source_id' => $source->id]);

        foreach ([$direct['boarding_stop_id'], $direct['alighting_stop_id']] as $stopId) {
            Stop::query()->whereKey($stopId)->update([
                'verification_state' => VerificationState::VERIFIED,
                'provenance_source_id' => $source->id,
                'boarding_precision' => BoardingPrecision::EXACT_BOARDING_POINT->value,
                'boarding_verification_state' => VerificationState::VERIFIED->value,
                'boarding_provenance_source_id' => $source->id,
            ]);
        }

        RoutePatternStop::query()->whereIn('id', [$direct['boarding_association_id'], $direct['alighting_association_id']])->update([
            'verification_state' => VerificationState::VERIFIED->value,
            'provenance_source_id' => $source->id,
            'service_verification_state' => VerificationState::VERIFIED->value,
            'sequence_verification_state' => VerificationState::VERIFIED->value,
            'boarding_verification_state' => VerificationState::VERIFIED->value,
            'alighting_verification_state' => VerificationState::VERIFIED->value,
            'service_provenance_source_id' => $source->id,
            'sequence_provenance_source_id' => $source->id,
            'boarding_provenance_source_id' => $source->id,
            'alighting_provenance_source_id' => $source->id,
        ]);
    }

    private function plannedTrust(Place $origin, Place $destination): string
    {
        $result = app(JourneyPlanner::class)->plan($this->loadPlace($origin->slug), $this->loadPlace($destination->slug), maxTransfers: 0);
        $journey = collect($result->journeys)->first(fn ($candidate): bool => collect($candidate->legs)->contains(fn ($leg): bool => $leg->type === JourneyLegType::RIDE));

        return $journey?->trustState->value ?? 'NO_JOURNEY';
    }

    private function loadPlace(string $slug): Place
    {
        return Place::query()
            ->when(config('database.default') === 'pgsql', fn ($query) => $query->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')]))
            ->where('slug', $slug)
            ->with('provenanceSource')
            ->firstOrFail();
    }
}
