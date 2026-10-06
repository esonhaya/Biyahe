<?php

namespace App\Services\FieldSurveys;

use App\Enums\TransferReviewStatus;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\TransferRelationship;
use App\Services\Evidence\EvidenceJourneyPlanCache;
use App\Services\Evidence\EvidencePriorityEngine;
use Illuminate\Support\Facades\DB;

final class FieldSurveyPlanner
{
    public function __construct(
        private readonly EvidencePriorityEngine $priorityEngine,
        private readonly EvidenceJourneyPlanCache $planCache,
    ) {}

    /** @return array<string, mixed> */
    public function plan(int $pairs = 6, int $maxTransfers = 2): array
    {
        $connection = DB::connection();
        $wasLoggingQueries = $connection->logging();
        if (! $wasLoggingQueries) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();
        }
        $queriesBefore = count($connection->getQueryLog());
        $startedAt = hrtime(true);

        try {
            $priority = $this->priorityEngine->analyze(100, $pairs, $maxTransfers, $this->planCache);
            $packages = [
                ...$this->routePackages('12L', $priority['items']),
                ...$this->routePackages('17B', $priority['items']),
                ...$this->transferPackages($priority['items']),
            ];
            usort($packages, static fn (array $left, array $right): int => (($left['priority_impact']['priority_rank'] ?? PHP_INT_MAX)
                    <=> ($right['priority_impact']['priority_rank'] ?? PHP_INT_MAX))
                ?: (($right['priority_impact']['at_least_affected_journeys'] ?? $right['priority_impact']['affected_journeys'] ?? 0)
                    <=> ($left['priority_impact']['at_least_affected_journeys'] ?? $left['priority_impact']['affected_journeys'] ?? 0))
                ?: strcmp($left['id'], $right['id']));
        } finally {
            $elapsedMilliseconds = round((hrtime(true) - $startedAt) / 1_000_000, 2);
            $databaseQueries = count($connection->getQueryLog()) - $queriesBefore;
            if (! $wasLoggingQueries) {
                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }
        }

        return [
            'priority_analysis' => $priority,
            'survey_packages' => $packages,
            'performance' => [
                'elapsed_milliseconds' => $elapsedMilliseconds,
                'database_queries' => $databaseQueries,
                ...$this->planCache->metrics(),
            ],
            'general_checklist' => [
                'Before boarding: confirm the route code and direction cue; record the actual waiting/loading area and a landmark without guessing a platform.',
                'During the ride: record observed stops in order, distinguish served/loading stops from pass-through locations, and note deviations.',
                'At the destination: record where alighting is allowed, the endpoint only if reached, and fare paid only as an observation.',
                'Record time, location method, and device accuracy where available; do not treat one trip as proof of every trip or a permanent pattern.',
            ],
            'privacy' => 'Start and stop recording within the survey only; omit unrelated movement before and after transit observation.',
        ];
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function routePackages(string $routeCode, array $items): array
    {
        $route = Route::query()
            ->where('code', $routeCode)
            ->where('is_demo', false)
            ->where('is_active', true)
            ->with(['city', 'patterns' => fn ($query) => $query->where('is_demo', false)->where('is_active', true)->with(['patternStops.stop'])])
            ->first();
        if ($route === null) {
            return [];
        }

        return $route->patterns->map(function (RoutePattern $pattern) use ($route, $items): ?array {
            $ids = collect([
                $pattern->stable_id,
                ...$pattern->patternStops->pluck('stable_id')->all(),
                ...$pattern->patternStops->map(fn ($association): ?string => $association->stop?->stable_id)->filter()->all(),
            ])
                ->filter()->flip();
            $related = array_values(array_filter($items, fn (array $item): bool => isset($ids[$item['stable_id'] ?? ''])
                || (($item['record_type'] ?? null) === 'transport_model' && ($item['stable_id'] ?? null) === $pattern->stable_id)));
            if ($related === []) {
                return null;
            }
            $associations = $pattern->patternStops->map(fn ($association): array => [
                'stable_id' => $association->stable_id,
                'stop' => $association->stop?->name,
                'sequence' => $association->sequence,
                'service' => $association->service_verification_state?->value ?? $association->service_verification_state,
                'sequence_evidence' => $association->sequence_verification_state?->value ?? $association->sequence_verification_state,
                'boarding' => $association->boarding_verification_state?->value ?? $association->boarding_verification_state,
                'alighting' => $association->alighting_verification_state?->value ?? $association->alighting_verification_state,
            ])->all();
            $journeys = array_sum(array_column($related, 'affected_journeys'));
            $maxSingleFactJourneys = $related === [] ? 0 : max(array_column($related, 'affected_journeys'));
            $pairs = [];
            foreach ($related as $item) {
                $pairs = [...$pairs, ...($item['affected_od_pairs'] ?? [])];
            }

            return [
                'id' => 'survey-'.$pattern->stable_id,
                'title' => $route->code.' '.$pattern->direction.' observed ride',
                'route' => ['code' => $route->code, 'stable_id' => $route->stable_id],
                'pattern' => ['stable_id' => $pattern->stable_id, 'direction' => $pattern->direction, 'trust' => $pattern->verification_state?->value ?? $pattern->verification_state],
                'target_facts' => $related,
                'priority_impact' => [
                    'priority_rank' => $related === [] ? null : min(array_column($related, 'rank')),
                    'at_least_affected_journeys' => $maxSingleFactJourneys,
                    'fact_journey_impacts_not_deduplicated' => $journeys,
                    'affected_od_pairs' => count(array_unique($pairs)),
                ],
                'offline_package_template' => $this->offlineTemplate(
                    'planned-'.$pattern->stable_id,
                    $route->code.' '.$pattern->direction.' field survey',
                    'Planned only. Collect and review observations in the field; this template contains no observations.',
                    $route->city->stable_id,
                    $route->stable_id,
                    $pattern->stable_id,
                ),
                'ordered_pattern_stops_to_check' => $associations,
                'checklist' => [
                    'before_boarding' => [
                        'Confirm the route code and visible destination/signboard cue.',
                        'Record the actual boarding area and a useful landmark; note location precision and accuracy.',
                        'Confirm the observed direction before boarding.',
                    ],
                    'during_ride' => [
                        'Record major observed service points in order; distinguish served stops from pass-through locations.',
                        'Record boarding and alighting opportunities and any unexpected deviations.',
                    ],
                    'at_end' => [
                        'Record the actual alighting point and endpoint only if reached.',
                        'Record fare paid only when intentionally observed; note uncertainty and variation.',
                    ],
                ],
            ];
        })->filter()->values()->all();
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function transferPackages(array $items): array
    {
        $impactById = [];
        foreach ($items as $item) {
            if (($item['record_type'] ?? null) === 'transfer_relationship' && isset($item['record_id'])) {
                $impactById[(int) $item['record_id']] = $item;
            }
        }
        $relationships = TransferRelationship::query()
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('review_status', [TransferReviewStatus::PENDING_REVIEW->value, TransferReviewStatus::NEEDS_RECHECK->value])
            ->with(['city', 'fromStop', 'toStop', 'fromRoutePattern.route', 'toRoutePattern.route'])
            ->orderBy('id')
            ->get();

        return $relationships->map(function (TransferRelationship $transfer) use ($impactById): array {
            $impact = $impactById[$transfer->id] ?? null;

            return [
                'id' => 'survey-transfer-'.$transfer->stable_id,
                'title' => 'Review interchange '.($transfer->landmark ?: ($transfer->fromStop?->name.' → '.$transfer->toStop?->name)),
                'transfer' => [
                    'stable_id' => $transfer->stable_id,
                    'from_stop' => $transfer->fromStop?->name,
                    'to_stop' => $transfer->toStop?->name,
                    'from_service' => $transfer->fromRoutePattern?->route?->code,
                    'to_service' => $transfer->toRoutePattern?->route?->code,
                    'distance_meters' => $transfer->distance_meters,
                    'trust' => $transfer->verification_state?->value ?? $transfer->verification_state,
                    'review_status' => $transfer->review_status?->value ?? $transfer->review_status,
                ],
                'priority_impact' => [
                    'priority_rank' => $impact['rank'] ?? null,
                    'affected_journeys' => $impact['affected_journeys'] ?? null,
                    'affected_od_pairs' => $impact['affected_od_pairs_count'] ?? null,
                ],
                'offline_package_template' => $this->offlineTemplate(
                    'planned-transfer-'.$transfer->stable_id,
                    'Transfer review: '.($transfer->landmark ?: ($transfer->fromStop?->name.' to '.$transfer->toStop?->name)),
                    'Planned only. Observe both services, direction and pedestrian feasibility. Do not treat proximity as a verified transfer.',
                    $transfer->city->stable_id,
                ),
                'checklist' => [
                    'Observe both services at their actual boarding points; record route code and direction separately.',
                    'Record boarding sides, a useful landmark, and any practical road crossing or access restriction.',
                    'Estimate walking separation and note the observed path; do not infer usability from stop proximity.',
                    'Record date, time, location precision, and whether the interchange is practically usable.',
                ],
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function offlineTemplate(
        string $stableId,
        string $title,
        string $purpose,
        string $cityStableId,
        ?string $routeStableId = null,
        ?string $patternStableId = null,
    ): array {
        return [
            'schema_version' => 1,
            'surveys' => [[
                'stable_id' => $stableId,
                'title' => $title,
                'purpose' => $purpose,
                'city_id' => $cityStableId,
                'route_id' => $routeStableId,
                'route_pattern_id' => $patternStableId,
                'observer_reference' => 'REPLACE_WITH_ACCOUNTABLE_OBSERVER_REFERENCE',
                'status' => 'PLANNED',
                'observations' => [],
                'trace_points' => [],
            ]],
        ];
    }
}
