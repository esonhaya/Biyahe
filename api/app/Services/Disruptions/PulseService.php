<?php

namespace App\Services\Disruptions;

use App\Enums\CommuterReportStatus;
use App\Enums\DisruptionSeverity;
use App\Enums\EvidenceConflictStatus;
use App\Models\CommuterReport;
use App\Models\Disruption;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class PulseService
{
    private const CACHE_GENERATION_KEY = 'biyahe:pulse:generation';

    public function __construct(private readonly ActiveDisruptionService $activeDisruptions) {}

    /**
     * Build one bounded commuter-facing operational summary. Raw report text,
     * reporter identity, and admin notes never leave this service.
     *
     * @return array<string, mixed>
     */
    public function summary(array $filters = [], ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $filters = $this->normaliseFilters($filters);
        $generation = (int) Cache::get(self::CACHE_GENERATION_KEY, 1);
        $key = 'biyahe:pulse:'.sha1(json_encode([
            'generation' => $generation,
            'filter' => $filters,
            'now' => $now->format('Y-m-d H:i'),
        ], JSON_THROW_ON_ERROR));
        $ttl = max(1, (int) config('biyahe.disruptions.pulse_cache_seconds', 10));

        return Cache::remember($key, $ttl, fn (): array => $this->build($filters, $now));
    }

    public function invalidate(): void
    {
        Cache::put(self::CACHE_GENERATION_KEY, (int) Cache::get(self::CACHE_GENERATION_KEY, 1) + 1, now()->addDay());
    }

    /** @return array<string, mixed> */
    public function triage(): array
    {
        $now = CarbonImmutable::now();
        $active = $this->activeDisruptions->active($now);
        $expiringSoon = $active->filter(fn (Disruption $disruption): bool => $disruption->ends_at !== null && $disruption->ends_at->lessThanOrEqualTo($now->addHours(6)))->count();
        $unreviewed = CommuterReport::query()->whereIn('status', [
            CommuterReportStatus::SUBMITTED->value,
            CommuterReportStatus::UNDER_REVIEW->value,
        ])->count();
        $accepted = CommuterReport::query()->where('status', CommuterReportStatus::ACCEPTED_AS_SIGNAL->value)->whereNull('disruption_id')->count();
        $conflicts = $active->filter(fn (Disruption $disruption): bool => $disruption->provenanceSource?->conflict_status === EvidenceConflictStatus::OPEN)->count();

        return [
            'active_advisories' => $active->count(),
            'expiring_soon' => $expiringSoon,
            'unreviewed_reports' => $unreviewed,
            'accepted_signals' => $accepted,
            'open_conflicts' => $conflicts,
            'high_impact_entities' => $active->filter(fn (Disruption $disruption): bool => $disruption->severity instanceof DisruptionSeverity && $disruption->severity->rank() >= DisruptionSeverity::MAJOR->rank())->sum(fn (Disruption $disruption): int => $this->entityCount($disruption)),
        ];
    }

    /** @return array<string, mixed> */
    private function build(array $filters, CarbonImmutable $now): array
    {
        $active = $this->activeDisruptions->active($now, $this->entityFilters($filters));
        $items = $active
            ->filter(fn (Disruption $disruption): bool => $this->passesItemFilter($disruption, $filters['filter']))
            ->map(fn (Disruption $disruption): array => $this->item($disruption, $now))
            ->sort(fn (array $left, array $right): int => $this->compareItems($left, $right))
            ->values()
            ->take((int) config('biyahe.disruptions.pulse_item_limit', 20))
            ->all();
        $signals = $this->communitySignals($filters, $now);
        $major = collect($items)->filter(fn (array $item): bool => in_array($item['severity'], [DisruptionSeverity::MAJOR->value, DisruptionSeverity::CRITICAL->value], true));
        $routeIds = collect($active)->flatMap(fn (Disruption $disruption): Collection => $disruption->routes->pluck('id'))->unique()->values();
        $stopIds = collect($active)->flatMap(fn (Disruption $disruption): Collection => $disruption->stops->pluck('id'))->unique()->values();
        $placeIds = collect($active)->flatMap(fn (Disruption $disruption): Collection => $disruption->places->pluck('id'))->unique()->values();
        $openConflicts = collect($items)->filter(fn (array $item): bool => ($item['conflict_status'] ?? null) === EvidenceConflictStatus::OPEN->value)->count()
            + collect($signals)->filter(fn (array $signal): bool => $signal['conflicted'] === true)->count();

        return [
            'summary' => [
                'active_advisories' => $active->count(),
                'major_or_critical' => $major->count(),
                'routes_affected' => $routeIds->count(),
                'stops_affected' => $stopIds->count(),
                'areas_affected' => $placeIds->count() + $active->filter(fn (Disruption $disruption): bool => $disruption->geometry_geo_json !== null && $disruption->places->isEmpty())->count(),
                'unresolved_public_signals' => count($signals),
                'open_conflicts' => $openConflicts,
            ],
            'network_status' => $this->networkStatus($active, $signals, $openConflicts),
            'items' => $items,
            'community_signals' => $signals,
            'meta' => [
                'generated_at' => $now->toIso8601String(),
                'filter' => $filters['filter'],
                'temporary_overlay' => true,
                'community_signals_are_non_authoritative' => true,
                'journey_context' => $this->contextLabel($filters),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function item(Disruption $disruption, CarbonImmutable $now): array
    {
        $summary = $this->activeDisruptions->publicSummary($disruption, $now);
        $summary['freshness_label'] = $this->freshnessLabel($disruption, $now);
        $summary['expected_end_label'] = $disruption->ends_at?->isToday()
            ? 'Scheduled until '.$disruption->ends_at->format('g:i A')
            : ($disruption->ends_at?->toDateString());
        $summary['affected_entity_count'] = $this->entityCount($disruption);
        $summary['conflict_status'] = $disruption->provenanceSource?->conflict_status?->value ?? $disruption->provenanceSource?->conflict_status;

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    private function communitySignals(array $filters, CarbonImmutable $now): array
    {
        $query = CommuterReport::query()
            ->with(['route', 'routePattern.route', 'stop', 'place'])
            ->where('status', CommuterReportStatus::ACCEPTED_AS_SIGNAL->value)
            ->whereNull('disruption_id')
            ->where('observed_at', '>=', $now->subHours((int) config('biyahe.disruptions.pulse_signal_window_hours', 24)))
            ->when(! app()->environment('testing'), fn (Builder $builder) => $builder->where('is_synthetic', false))
            ->when(isset($filters['route_id']), fn (Builder $builder) => $builder->where('route_id', $filters['route_id']))
            ->when(isset($filters['stop_id']), fn (Builder $builder) => $builder->where('stop_id', $filters['stop_id']))
            ->when(isset($filters['place_id']), fn (Builder $builder) => $builder->where('place_id', $filters['place_id']))
            ->latest('observed_at')
            ->limit((int) config('biyahe.disruptions.pulse_signal_limit', 100));
        $reports = $query->get()->filter(fn (CommuterReport $report): bool => $this->passesReportFilter($report, $filters['filter']));
        $groups = $reports->groupBy(fn (CommuterReport $report): string => implode(':', [
            $report->category?->value ?? $report->category,
            $report->route_id ?? '-',
            $report->stop_id ?? '-',
            $report->place_id ?? '-',
        ]));

        return $groups->map(function (Collection $group) use ($now): array {
            /** @var CommuterReport $latest */
            $latest = $group->sortByDesc('observed_at')->first();
            $categories = $group->pluck('category')->map(fn ($category): string => $category?->value ?? (string) $category)->unique()->values()->all();

            return [
                'category' => $categories[0] ?? 'OTHER',
                'count' => $group->count(),
                'observed_at' => $latest->observed_at?->toIso8601String(),
                'freshness_label' => $this->freshnessLabelForDate($latest->observed_at, $now),
                'route' => $latest->route === null ? null : ['id' => $latest->route->getKey(), 'code' => $latest->route->code, 'name' => $latest->route->name],
                'stop' => $latest->stop === null ? null : ['id' => $latest->stop->getKey(), 'name' => $latest->stop->name],
                'place' => $latest->place === null ? null : ['id' => $latest->place->getKey(), 'name' => $latest->place->name],
                'message' => $this->signalMessage($group->count(), $latest),
                'conflicted' => count($categories) > 1,
                'authoritative' => false,
            ];
        })->sortByDesc(fn (array $signal): int => $signal['count'])->values()->take(20)->all();
    }

    /** @return array<string, mixed> */
    private function networkStatus(Collection $active, array $signals, int $openConflicts): array
    {
        $maxSeverity = $active->max(fn (Disruption $disruption): int => $disruption->severity instanceof DisruptionSeverity ? $disruption->severity->rank() : 0);
        if ($maxSeverity >= DisruptionSeverity::CRITICAL->rank()) {
            return ['code' => 'MAJOR_DISRUPTION', 'label' => 'Major disruption', 'explanation' => 'A reviewed critical advisory is active. Check affected journeys before leaving.'];
        }
        if ($maxSeverity >= DisruptionSeverity::MAJOR->rank()) {
            return ['code' => 'DISRUPTED', 'label' => 'Some services are affected', 'explanation' => 'Reviewed advisories are affecting part of the supported network.'];
        }
        if ($openConflicts > 0 || $signals !== []) {
            return ['code' => 'STATUS_UNCERTAIN', 'label' => 'Some conditions are still being checked', 'explanation' => 'Recent commuter signals or conflicting reports are not authoritative service status.'];
        }
        if ($active->isNotEmpty()) {
            return ['code' => 'MINOR_ISSUES', 'label' => 'Minor commute issues reported', 'explanation' => 'Reviewed information is available, but no major network disruption is active.'];
        }

        return ['code' => 'NO_KNOWN_ACTIVE_ADVISORIES', 'label' => 'No known active reviewed advisories', 'explanation' => 'This is not a guarantee that every service is operating normally.'];
    }

    private function compareItems(array $left, array $right): int
    {
        $severity = ['CRITICAL' => 4, 'MAJOR' => 3, 'MINOR' => 2, 'INFO' => 1];
        $source = ['OFFICIAL' => 5, 'OPERATOR' => 4, 'ADMIN_FIELD_REPORT' => 3, 'TRUSTED_CONTRIBUTOR' => 2, 'OTHER_REVIEWED_SOURCE' => 1, 'PUBLIC_REPORT' => 0];

        return (($severity[$right['severity']] ?? 0) <=> ($severity[$left['severity']] ?? 0))
            ?: (($source[$right['source_class']] ?? 0) <=> ($source[$left['source_class']] ?? 0))
            ?: strcmp((string) $left['stable_id'], (string) $right['stable_id']);
    }

    private function entityCount(Disruption $disruption): int
    {
        return $disruption->routes->count() + $disruption->patterns->count() + $disruption->stops->count() + $disruption->places->count() + $disruption->transfers->count() + ($disruption->geometry_geo_json === null ? 0 : 1);
    }

    private function signalMessage(int $count, CommuterReport $report): string
    {
        $subject = $report->route?->code ?? $report->stop?->name ?? $report->place?->name ?? 'this area';

        return $count === 1
            ? 'One recent commuter report mentions '.$subject.'. This is a signal for review, not confirmed service status.'
            : $count.' recent commuter reports mention '.$subject.'. These are signals for review, not confirmed service status.';
    }

    private function freshnessLabel(Disruption $disruption, CarbonImmutable $now): string
    {
        $date = $disruption->provenanceSource?->checked_at ?? $disruption->starts_at;

        return $this->freshnessLabelForDate($date, $now);
    }

    private function freshnessLabelForDate(?CarbonInterface $date, CarbonImmutable $now): string
    {
        if ($date === null) {
            return 'Freshness not recorded';
        }
        $minutes = max(0, $date->diffInMinutes($now));
        if ($minutes < 60) {
            return $minutes.' min ago';
        }
        if ($date->isToday()) {
            return 'Checked today';
        }

        return $date->diffInDays($now).' days ago';
    }

    /** @return array<string, mixed> */
    private function normaliseFilters(array $filters): array
    {
        $filter = strtoupper((string) ($filters['filter'] ?? 'ALL'));

        return [
            'filter' => in_array($filter, ['ALL', 'ROUTES', 'STOPS', 'AREAS', 'MAJOR'], true) ? $filter : 'ALL',
            'route_id' => isset($filters['route_id']) ? (int) $filters['route_id'] : null,
            'stop_id' => isset($filters['stop_id']) ? (int) $filters['stop_id'] : null,
            'place_id' => isset($filters['place_id']) ? (int) $filters['place_id'] : null,
        ];
    }

    /** @return array<string, int> */
    private function entityFilters(array $filters): array
    {
        return array_filter([
            'route_id' => $filters['route_id'],
            'stop_id' => $filters['stop_id'],
            'place_id' => $filters['place_id'],
        ], static fn ($value): bool => $value !== null);
    }

    private function passesItemFilter(Disruption $disruption, string $filter): bool
    {
        return match ($filter) {
            'ROUTES' => $disruption->routes->isNotEmpty() || $disruption->patterns->isNotEmpty(),
            'STOPS' => $disruption->stops->isNotEmpty() || $disruption->transfers->isNotEmpty(),
            'AREAS' => $disruption->places->isNotEmpty() || $disruption->geometry_geo_json !== null,
            'MAJOR' => $disruption->severity instanceof DisruptionSeverity && $disruption->severity->rank() >= DisruptionSeverity::MAJOR->rank(),
            default => true,
        };
    }

    private function passesReportFilter(CommuterReport $report, string $filter): bool
    {
        return match ($filter) {
            'ROUTES' => $report->route_id !== null || $report->route_pattern_id !== null,
            'STOPS' => $report->stop_id !== null || $report->transfer_relationship_id !== null,
            'AREAS' => $report->place_id !== null || $report->location_point !== null,
            default => true,
        };
    }

    private function contextLabel(array $filters): ?string
    {
        if ($filters['route_id'] !== null) {
            return 'ROUTE';
        }
        if ($filters['stop_id'] !== null) {
            return 'STOP';
        }
        if ($filters['place_id'] !== null) {
            return 'PLACE';
        }

        return null;
    }
}
