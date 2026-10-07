<?php

namespace App\Services\Disruptions;

use App\Enums\CommuterReportCategory;
use App\Enums\CommuterReportStatus;
use App\Enums\DisruptionCategory;
use App\Enums\DisruptionSeverity;
use App\Enums\DisruptionSourceClass;
use App\Enums\DisruptionStatus;
use App\Models\CommuterReport;
use App\Models\CommuterReportReview;
use App\Models\Disruption;
use App\Models\DisruptionReview;
use App\Models\ProvenanceSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommuterReportService
{
    public function submit(array $data, ?string $reporterToken = null): CommuterReport
    {
        $observedAt = CarbonImmutable::parse($data['observed_at']);
        $description = trim(strip_tags((string) $data['description']));
        $duplicate = $this->findDuplicate($data, $description, $observedAt);
        $report = new CommuterReport([
            'stable_id' => 'commuter-report-'.Str::lower((string) Str::ulid()),
            'category' => $data['category'],
            'status' => $duplicate === null ? CommuterReportStatus::SUBMITTED : CommuterReportStatus::DUPLICATE,
            'description' => $description,
            'observed_at' => $observedAt,
            'submitted_at' => now(),
            'source_reference' => isset($data['source_reference']) ? trim((string) $data['source_reference']) : null,
            'location_accuracy_m' => $data['location_accuracy_m'] ?? null,
            'route_id' => $data['route_id'] ?? null,
            'route_pattern_id' => $data['route_pattern_id'] ?? null,
            'stop_id' => $data['stop_id'] ?? null,
            'place_id' => $data['place_id'] ?? null,
            'transfer_relationship_id' => $data['transfer_relationship_id'] ?? null,
            'duplicate_of_id' => $duplicate?->getKey(),
            'reporter_token_hash' => $reporterToken === null ? null : hash('sha256', $reporterToken),
        ]);
        if (isset($data['location']) && is_array($data['location'])) {
            $report->location = $data['location'];
        }
        $report->save();

        return $report->fresh([
            'route',
            'routePattern',
            'stop',
            'place',
            'transferRelationship',
            'duplicateOf',
        ]);
    }

    public function review(
        CommuterReport $report,
        CommuterReportStatus $status,
        ?User $actor = null,
        ?string $reason = null,
        ?int $duplicateOfId = null,
    ): CommuterReport {
        $allowed = [
            CommuterReportStatus::UNDER_REVIEW,
            CommuterReportStatus::ACCEPTED_AS_SIGNAL,
            CommuterReportStatus::REJECTED,
            CommuterReportStatus::DUPLICATE,
            CommuterReportStatus::EXPIRED,
        ];
        if (! in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Reports can only move through the review lifecycle.');
        }

        return DB::transaction(function () use ($report, $status, $actor, $reason, $duplicateOfId): CommuterReport {
            $from = $report->status?->value ?? (string) $report->status;
            $report->status = $status;
            $report->reviewed_by_user_id = $actor?->getKey();
            $report->reviewed_at = now();
            $report->review_notes = $reason;
            if ($duplicateOfId !== null) {
                $report->duplicate_of_id = $duplicateOfId;
            }
            $report->save();
            CommuterReportReview::query()->create([
                'commuter_report_id' => $report->getKey(),
                'actor_user_id' => $actor?->getKey(),
                'from_status' => $from,
                'to_status' => $status->value,
                'reason' => $reason,
            ]);

            return $report->fresh();
        });
    }

    public function corroboration(CommuterReport $report): array
    {
        $window = (int) config('biyahe.disruptions.report_duplicate_window_minutes', 20);
        $from = $report->observed_at->subMinutes($window);
        $to = $report->observed_at->addMinutes($window);
        $query = CommuterReport::query()
            ->where($report->getKeyName(), '<>', $report->getKey())
            ->where('status', CommuterReportStatus::ACCEPTED_AS_SIGNAL->value)
            ->whereBetween('observed_at', [$from, $to]);
        foreach (['route_id', 'route_pattern_id', 'stop_id', 'place_id', 'transfer_relationship_id'] as $field) {
            $value = $report->getAttribute($field);
            if ($value !== null) {
                $query->where($field, $value);
            }
        }
        $matchingQuery = (clone $query)->where('category', $report->category?->value ?? $report->category);
        $conflictQuery = (clone $query)->where('category', '<>', $report->category?->value ?? $report->category);

        return [
            'accepted_matching_reports' => $matchingQuery->count(),
            'conflicting_accepted_reports' => $conflictQuery->count(),
            'window_minutes' => $window,
            'is_conflicted' => $conflictQuery->exists(),
            'is_canonical_evidence' => false,
        ];
    }

    public function promoteToAdvisory(CommuterReport $report, User $actor, ?string $reviewNote = null): Disruption
    {
        if ($report->status !== CommuterReportStatus::ACCEPTED_AS_SIGNAL) {
            throw new \InvalidArgumentException('Only an accepted commuter signal can be promoted to a reviewed temporary advisory.');
        }
        if ($report->is_synthetic) {
            throw new \InvalidArgumentException('Synthetic reports cannot be promoted into production advisories.');
        }
        if ($report->category === CommuterReportCategory::FARE_OBSERVATION) {
            throw new \InvalidArgumentException('Fare observations remain evidence signals and cannot become service advisories.');
        }

        $report->loadMissing(['route', 'routePattern.route', 'stop', 'place', 'transferRelationship']);

        return DB::transaction(function () use ($report, $actor, $reviewNote): Disruption {
            $source = ProvenanceSource::query()->create([
                'source_type' => DisruptionSourceClass::PUBLIC_REPORT->value,
                'title' => 'Reviewed commuter report '.$report->stable_id,
                'publisher' => 'Biyahe commuter report',
                'url' => $report->source_reference,
                'accessed_at' => now()->toDateString(),
                'observed_at' => $report->observed_at?->toDateString(),
                'checked_at' => now()->toDateString(),
                'notes' => $reviewNote,
                'evidence_scope' => ['temporary_disruption_signal'],
            ]);
            $category = $this->disruptionCategory($report->category);
            $disruption = Disruption::query()->create([
                'stable_id' => 'advisory-'.$report->stable_id,
                'city_id' => $this->cityId($report),
                'provenance_source_id' => $source->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'reviewed_by_user_id' => $actor->getKey(),
                'category' => $category,
                'headline' => $this->headline($report),
                'description' => $report->description,
                'severity' => DisruptionSeverity::MINOR,
                'status' => DisruptionStatus::ACTIVE,
                'source_class' => DisruptionSourceClass::PUBLIC_REPORT,
                'starts_at' => $report->observed_at,
                'ends_at' => now()->addHours((int) config('biyahe.disruptions.default_expiry_hours.'.$category->value, 12)),
                'reviewed_at' => now(),
                'review_notes' => $reviewNote,
            ]);
            if ($report->location_point !== null) {
                $disruption->geometry = [
                    'type' => 'Point',
                    'coordinates' => [$report->location_point['lng'], $report->location_point['lat']],
                ];
                $disruption->geometry_type = 'Point';
                $disruption->save();
            }
            if ($report->route_id !== null) {
                $disruption->routes()->sync([$report->route_id]);
            }
            if ($report->route_pattern_id !== null) {
                $disruption->patterns()->sync([$report->route_pattern_id]);
            }
            if ($report->stop_id !== null) {
                $disruption->stops()->sync([$report->stop_id]);
            }
            if ($report->place_id !== null) {
                $disruption->places()->sync([$report->place_id]);
            }
            if ($report->transfer_relationship_id !== null) {
                $disruption->transfers()->sync([$report->transfer_relationship_id]);
            }
            $report->disruption_id = $disruption->getKey();
            $report->review_notes = $reviewNote ?? $report->review_notes;
            $report->save();
            DisruptionReview::query()->create([
                'disruption_id' => $disruption->getKey(),
                'actor_user_id' => $actor->getKey(),
                'from_status' => null,
                'to_status' => DisruptionStatus::ACTIVE->value,
                'reason' => 'Explicitly promoted from reviewed commuter report.'.($reviewNote === null ? '' : ' '.$reviewNote),
            ]);

            return $disruption->fresh(['provenanceSource', 'routes', 'patterns', 'stops', 'places', 'transfers']);
        });
    }

    private function findDuplicate(array $data, string $description, CarbonImmutable $observedAt): ?CommuterReport
    {
        $minutes = (int) config('biyahe.disruptions.report_duplicate_window_minutes', 20);
        $query = CommuterReport::query()
            ->where('category', $data['category'])
            ->whereIn('status', [
                CommuterReportStatus::SUBMITTED->value,
                CommuterReportStatus::UNDER_REVIEW->value,
                CommuterReportStatus::ACCEPTED_AS_SIGNAL->value,
            ])
            ->whereBetween('observed_at', [$observedAt->subMinutes($minutes), $observedAt->addMinutes($minutes)]);
        foreach (['route_id', 'route_pattern_id', 'stop_id', 'place_id', 'transfer_relationship_id'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        return $query->get()->first(function (CommuterReport $existing) use ($description): bool {
            return hash_equals(
                hash('sha256', mb_strtolower(trim($existing->description))),
                hash('sha256', mb_strtolower(trim($description))),
            );
        });
    }

    private function disruptionCategory(CommuterReportCategory $category): DisruptionCategory
    {
        return match ($category) {
            CommuterReportCategory::ROUTE_NOT_SEEN => DisruptionCategory::OTHER,
            CommuterReportCategory::SERVICE_STOPPED => DisruptionCategory::SERVICE_SUSPENDED,
            CommuterReportCategory::REROUTE => DisruptionCategory::REROUTED,
            CommuterReportCategory::STOP_BLOCKED => DisruptionCategory::STOP_UNAVAILABLE,
            CommuterReportCategory::FLOODING => DisruptionCategory::FLOODING,
            CommuterReportCategory::ROAD_CLOSURE => DisruptionCategory::ROAD_CLOSURE,
            CommuterReportCategory::TRANSFER_PROBLEM => DisruptionCategory::TRANSFER_UNAVAILABLE,
            CommuterReportCategory::FARE_OBSERVATION, CommuterReportCategory::OTHER => DisruptionCategory::OTHER,
        };
    }

    private function headline(CommuterReport $report): string
    {
        return match ($report->category) {
            CommuterReportCategory::ROUTE_NOT_SEEN => 'A route was not observed recently.',
            CommuterReportCategory::SERVICE_STOPPED => 'A commuter reported stopped service.',
            CommuterReportCategory::REROUTE => 'A possible route change was reported.',
            CommuterReportCategory::STOP_BLOCKED => 'A stop may be blocked.',
            CommuterReportCategory::FLOODING => 'Flooding was reported nearby.',
            CommuterReportCategory::ROAD_CLOSURE => 'A road closure was reported nearby.',
            CommuterReportCategory::TRANSFER_PROBLEM => 'A transfer problem was reported.',
            CommuterReportCategory::FARE_OBSERVATION => 'A fare observation was submitted.',
            CommuterReportCategory::OTHER => 'A commuter reported a possible commute disruption.',
        };
    }

    private function cityId(CommuterReport $report): ?int
    {
        foreach ([$report->route?->city_id, $report->routePattern?->route?->city_id, $report->stop?->city_id, $report->place?->city_id, $report->transferRelationship?->city_id] as $cityId) {
            if ($cityId !== null) {
                return (int) $cityId;
            }
        }

        return null;
    }
}
