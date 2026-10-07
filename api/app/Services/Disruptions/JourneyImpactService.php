<?php

namespace App\Services\Disruptions;

use App\Enums\DisruptionCategory;
use App\Enums\DisruptionImpactLevel;
use App\Enums\DisruptionSeverity;
use App\Enums\DisruptionSourceClass;
use App\Enums\EvidenceConflictStatus;
use App\Models\Disruption;
use App\Services\Routing\Journey;
use App\Services\Routing\JourneyLeg;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class JourneyImpactService
{
    /** @var array<string, int|float> */
    private array $lastMetrics = [
        'journeys_checked' => 0,
        'active_advisories' => 0,
        'impact_matches' => 0,
        'disruption_queries' => 0,
        'match_time_milliseconds' => 0.0,
    ];

    public function __construct(private readonly ActiveDisruptionService $activeDisruptions) {}

    /** @return array<string, int|float> */
    public function metrics(): array
    {
        return $this->lastMetrics;
    }

    /**
     * Decorate candidates using one active-advisory collection. The caller can
     * then remove only hard closures and rank the remaining journeys.
     *
     * @param  list<Journey>  $journeys
     * @return list<Journey>
     */
    public function decorate(array $journeys, ?CarbonImmutable $now = null): array
    {
        $started = hrtime(true);
        $active = $this->activeDisruptions->active($now);
        $decorated = array_map(
            fn (Journey $journey): Journey => $this->decorateJourney($journey, $active, $now),
            $journeys,
        );
        $this->lastMetrics = [
            'journeys_checked' => count($journeys),
            'active_advisories' => $active->count(),
            'impact_matches' => array_sum(array_map(static fn (Journey $journey): int => count($journey->disruptions), $decorated)),
            'disruption_queries' => 1,
            'match_time_milliseconds' => round((hrtime(true) - $started) / 1_000_000, 2),
        ];

        return $decorated;
    }

    /** @param list<Journey> $journeys @return list<Journey> */
    public function removeHardClosures(array $journeys): array
    {
        return array_values(array_filter(
            $journeys,
            static fn (Journey $journey): bool => $journey->operationalImpact !== DisruptionImpactLevel::SERVICE_UNAVAILABLE->value,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function forCanIRide(int $routeId, ?int $patternId = null, array $stopIds = [], ?CarbonImmutable $now = null): array
    {
        $active = $this->activeDisruptions->active($now);
        $impacts = [];
        foreach ($active as $disruption) {
            $matches = [];
            if ($disruption->routes->contains('id', $routeId)) {
                $matches[] = 'ROUTE';
            }
            if ($patternId !== null && $disruption->patterns->contains('id', $patternId)) {
                $matches[] = 'ROUTE_PATTERN';
            }
            if ($stopIds !== [] && $disruption->stops->whereIn('id', $stopIds)->isNotEmpty()) {
                $matches[] = 'STOP';
            }
            if ($matches !== []) {
                $impacts[] = $this->impactSummary($disruption, $this->levelFor($disruption), null, null, array_values(array_unique($matches)), $now);
            }
        }

        return $this->sortImpacts($impacts);
    }

    /** @return array<string, mixed> */
    private function decorateJourney(Journey $journey, Collection $active, ?CarbonImmutable $now): Journey
    {
        /** @var array<int, array<string, mixed>> $byDisruption */
        $byDisruption = [];
        foreach ($journey->legs as $index => $leg) {
            foreach ($active as $disruption) {
                $matchedBy = $this->matchedBy($journey, $leg, $disruption);
                if ($matchedBy === []) {
                    continue;
                }

                $key = (int) $disruption->getKey();
                if (! isset($byDisruption[$key])) {
                    $byDisruption[$key] = $this->impactSummary(
                        $disruption,
                        $this->levelFor($disruption),
                        $index,
                        $leg,
                        $matchedBy,
                        $now,
                    );
                    $byDisruption[$key]['affected_legs'] = [];
                }
                $byDisruption[$key]['affected_legs'][] = [
                    'index' => $index,
                    'type' => $leg->type->value,
                    'matched_by' => $matchedBy,
                ];
                $byDisruption[$key]['matched_by'] = array_values(array_unique(array_merge($byDisruption[$key]['matched_by'], $matchedBy)));
                if ($this->isHardClosure($disruption, $matchedBy)) {
                    $byDisruption[$key]['hard_closure'] = true;
                    $byDisruption[$key]['impact_level'] = DisruptionImpactLevel::SERVICE_UNAVAILABLE->value;
                }
            }
        }

        $impacts = $this->sortImpacts(array_values($byDisruption));
        $level = $impacts[0]['impact_level'] ?? DisruptionImpactLevel::INFORMATIONAL->value;

        return $journey->withOperationalImpacts($impacts, $level);
    }

    /** @return list<string> */
    private function matchedBy(Journey $journey, JourneyLeg $leg, Disruption $disruption): array
    {
        $matched = [];
        $routeId = isset($leg->route['id']) ? (int) $leg->route['id'] : null;
        $patternId = isset($leg->pattern['id']) ? (int) $leg->pattern['id'] : null;
        $stopIds = array_values(array_filter([
            isset($leg->boardingStop['id']) ? (int) $leg->boardingStop['id'] : null,
            isset($leg->alightingStop['id']) ? (int) $leg->alightingStop['id'] : null,
            isset($leg->origin['id']) && ($leg->origin['type'] ?? null) === 'STOP' ? (int) $leg->origin['id'] : null,
            isset($leg->destination['id']) && ($leg->destination['type'] ?? null) === 'STOP' ? (int) $leg->destination['id'] : null,
        ]));

        if ($routeId !== null && $disruption->routes->contains('id', $routeId)) {
            $matched[] = 'ROUTE';
        }
        if ($patternId !== null && $disruption->patterns->contains('id', $patternId)) {
            $matched[] = 'ROUTE_PATTERN';
        }
        if ($stopIds !== [] && $disruption->stops->whereIn('id', $stopIds)->isNotEmpty()) {
            $matched[] = 'STOP';
        }

        $transferId = isset($leg->transfer['id']) ? (int) $leg->transfer['id'] : null;
        if ($transferId !== null && $disruption->transfers->contains('id', $transferId)) {
            $matched[] = 'TRANSFER';
        }

        $placeId = null;
        if (in_array($leg->type->value, ['WALK_ACCESS', 'WALK_EGRESS'], true)) {
            $point = $leg->type->value === 'WALK_ACCESS' ? $journey->origin : $journey->destination;
            if (($point['type'] ?? null) === 'PLACE' && isset($point['id'])) {
                $placeId = (int) $point['id'];
            }
        }
        if ($placeId !== null && $disruption->places->contains('id', $placeId)) {
            $matched[] = 'PLACE';
        }

        return array_values(array_unique($matched));
    }

    /** @return array<string, mixed> */
    private function impactSummary(
        Disruption $disruption,
        DisruptionImpactLevel $level,
        ?int $legIndex,
        ?JourneyLeg $leg,
        array $matchedBy,
        ?CarbonImmutable $now,
    ): array {
        $summary = $this->activeDisruptions->publicSummary($disruption, $now);
        $hard = $this->isHardClosure($disruption, $matchedBy);
        if ($hard) {
            $level = DisruptionImpactLevel::SERVICE_UNAVAILABLE;
        }

        return [
            'advisory' => $summary,
            'impact_level' => $level->value,
            'hard_closure' => $hard,
            'affected_leg_index' => $legIndex,
            'affected_leg_type' => $leg?->type->value,
            'matched_by' => array_values(array_unique($matchedBy)),
            'warning' => $this->warningText($disruption, $level),
        ];
    }

    private function levelFor(Disruption $disruption): DisruptionImpactLevel
    {
        if ($disruption->source_class === DisruptionSourceClass::PUBLIC_REPORT || $this->hasOpenSourceConflict($disruption)) {
            return DisruptionImpactLevel::POSSIBLY_AFFECTED;
        }
        if ($disruption->severity === DisruptionSeverity::INFO) {
            return DisruptionImpactLevel::INFORMATIONAL;
        }

        return DisruptionImpactLevel::AFFECTED;
    }

    /** @param list<string> $matchedBy */
    private function isHardClosure(Disruption $disruption, array $matchedBy): bool
    {
        return $matchedBy !== []
            && $disruption->category instanceof DisruptionCategory
            && $disruption->category->canHardClose()
            && $disruption->source_class instanceof DisruptionSourceClass
            && $disruption->source_class->canHardClose()
            && $disruption->reviewed_at !== null
            && $disruption->provenance_source_id !== null
            && ! $this->hasOpenSourceConflict($disruption)
            && $disruption->severity instanceof DisruptionSeverity
            && $disruption->severity->rank() >= DisruptionSeverity::MAJOR->rank();
    }

    private function hasOpenSourceConflict(Disruption $disruption): bool
    {
        return $disruption->provenanceSource?->conflict_status === EvidenceConflictStatus::OPEN
            || $disruption->provenanceSource?->superseded_by_id !== null;
    }

    private function warningText(Disruption $disruption, DisruptionImpactLevel $level): string
    {
        return match ($level) {
            DisruptionImpactLevel::SERVICE_UNAVAILABLE => $disruption->headline.' This service or access point is temporarily unavailable.',
            DisruptionImpactLevel::AFFECTED => $disruption->headline.' This journey may be affected.',
            DisruptionImpactLevel::POSSIBLY_AFFECTED => $disruption->headline.' This is a report that may affect this journey and is still being checked.',
            DisruptionImpactLevel::INFORMATIONAL => $disruption->headline,
        };
    }

    /** @param list<array<string, mixed>> $impacts @return list<array<string, mixed>> */
    private function sortImpacts(array $impacts): array
    {
        usort($impacts, static function (array $left, array $right): int {
            $level = ($right['impact_level'] === 'SERVICE_UNAVAILABLE' ? 3 : ($right['impact_level'] === 'AFFECTED' ? 2 : ($right['impact_level'] === 'POSSIBLY_AFFECTED' ? 1 : 0)))
                <=> ($left['impact_level'] === 'SERVICE_UNAVAILABLE' ? 3 : ($left['impact_level'] === 'AFFECTED' ? 2 : ($left['impact_level'] === 'POSSIBLY_AFFECTED' ? 1 : 0)));

            return $level ?: strcmp((string) ($left['advisory']['stable_id'] ?? ''), (string) ($right['advisory']['stable_id'] ?? ''));
        });

        return $impacts;
    }
}
