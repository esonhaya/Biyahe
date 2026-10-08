<?php

namespace App\Services\FieldSurveys;

use App\Enums\FieldMissionClaimStatus;
use App\Enums\FieldMissionStatus;
use App\Enums\FieldObservationStatus;
use App\Enums\FieldObservationType;
use App\Models\City;
use App\Models\FieldMission;
use App\Models\FieldMissionClaim;
use App\Models\FieldObservation;
use App\Models\FieldReviewAction;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\Stop;
use App\Models\TransportSurveySession;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class FieldMissionService
{
    public const SNAPSHOT_VERSION = 1;

    /** @param array<string, mixed> $campaign @return array{missions: int, claims: int} */
    public function syncCampaign(array $campaign, ?User $creator = null): array
    {
        if (($campaign['promotion_gate']['automatic_promotions'] ?? null) !== 0) {
            throw new RuntimeException('A campaign with an automatic promotion path cannot be persisted.');
        }

        return DB::transaction(function () use ($campaign, $creator): array {
            $missionCount = 0;
            $claimCount = 0;
            foreach (($campaign['missions'] ?? []) as $missionData) {
                if (! is_array($missionData) || ! is_string($missionData['id'] ?? null)) {
                    continue;
                }
                $claimRows = array_values(array_filter(($campaign['claims'] ?? []), function (mixed $claim) use ($missionData): bool {
                    return is_array($claim) && in_array($claim['claim_id'] ?? null, $missionData['claim_ids'] ?? [], true);
                }));
                $mission = FieldMission::query()->where('stable_id', $missionData['id'])->first();
                $attributes = [
                    'title' => mb_substr((string) ($missionData['title'] ?? 'Ground-truth mission'), 0, 180),
                    'area' => mb_substr((string) ($missionData['start_area'] ?? 'Cebu'), 0, 180),
                    'priority' => $this->missionPriority($claimRows),
                    'claim_checklist' => $missionData['records_needed'] ?? [],
                    'route_scope' => array_values(array_unique(array_map('strval', $missionData['route_scope'] ?? []))),
                    'instructions' => (string) ($missionData['observe'] ?? 'Record only the planned transport observations.'),
                    'safety_notes' => (string) ($missionData['safety_notes'] ?? 'Do not record unrelated movement.'),
                    'source_campaign_version' => (string) ($campaign['campaign_version'] ?? 'batch019-ground-truth-v1'),
                    'is_synthetic' => false,
                    'import_hash' => hash('sha256', json_encode([$missionData, $claimRows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                ];
                if ($mission === null) {
                    $mission = FieldMission::query()->create([
                        'stable_id' => $missionData['id'],
                        ...$attributes,
                        'status' => FieldMissionStatus::PLANNED,
                        'created_by_user_id' => $creator?->getKey(),
                    ]);
                    $missionCount++;
                } else {
                    $mission->forceFill($attributes)->save();
                }

                foreach ($claimRows as $claimData) {
                    $claim = $this->claimAttributes($mission, $claimData);
                    $existing = FieldMissionClaim::query()->where('stable_id', $claim['stable_id'])->first();
                    if ($existing === null) {
                        FieldMissionClaim::query()->create($claim);
                        $claimCount++;
                    } elseif (($existing->current_status?->value ?? $existing->current_status) === FieldMissionClaimStatus::OPEN
                        && ! $existing->observations()->exists()) {
                        $existing->forceFill(array_diff_key($claim, [
                            'stable_id' => true,
                            'field_mission_id' => true,
                            'current_status' => true,
                        ]))->save();
                    }
                }
            }

            return ['missions' => $missionCount, 'claims' => $claimCount];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listFor(User $operator): array
    {
        return FieldMission::query()
            ->where('is_synthetic', false)
            ->with(['claims', 'sessions' => fn ($query) => $query->where('observer_user_id', $operator->getKey())->latest('id')])
            ->orderByDesc('priority')
            ->orderBy('area')
            ->get()
            ->map(fn (FieldMission $mission): array => $this->snapshot($mission, $operator))
            ->all();
    }

    /** @return array<string, mixed> */
    public function snapshot(FieldMission $mission, ?User $operator = null): array
    {
        $mission->loadMissing(['claims', 'sessions']);
        $session = $operator === null
            ? $mission->sessions->sortByDesc('id')->first()
            : $mission->sessions->first(fn (TransportSurveySession $item): bool => (int) $item->observer_user_id === (int) $operator->getKey());

        return [
            'schema_version' => self::SNAPSHOT_VERSION,
            'id' => $mission->getKey(),
            'stable_id' => $mission->stable_id,
            'title' => $mission->title,
            'area' => $mission->area,
            'status' => $mission->status?->value ?? $mission->status,
            'priority' => $mission->priority,
            'route_scope' => $mission->route_scope ?? [],
            'instructions' => $mission->instructions,
            'safety_notes' => $mission->safety_notes,
            'claim_checklist' => $mission->claim_checklist ?? [],
            'claims' => $mission->claims->map(fn (FieldMissionClaim $claim): array => $this->claimSnapshot($claim))->values()->all(),
            'session' => $session === null ? null : [
                'id' => $session->getKey(),
                'stable_id' => $session->stable_id,
                'status' => $session->status?->value ?? $session->status,
                'observation_count' => $session->observations()->count(),
            ],
            'offline_note' => 'Mission data is a bounded snapshot. New claims or current network facts require a connected refresh.',
        ];
    }

    public function startSession(FieldMission $mission, User $operator): TransportSurveySession
    {
        return DB::transaction(function () use ($mission, $operator): TransportSurveySession {
            $mission = FieldMission::query()->lockForUpdate()->findOrFail($mission->getKey());
            if ($mission->is_synthetic) {
                throw ValidationException::withMessages(['mission' => 'Synthetic missions cannot collect production observations.']);
            }
            $existing = TransportSurveySession::query()
                ->where('field_mission_id', $mission->getKey())
                ->where('observer_user_id', $operator->getKey())
                ->whereIn('status', [FieldMissionStatus::IN_PROGRESS->value, FieldMissionStatus::SUBMITTED->value])
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $cityId = City::query()->where('is_demo', false)->where('is_active', true)->orderBy('id')->value('id');
            if ($cityId === null) {
                throw ValidationException::withMessages(['mission' => 'No active production city is available.']);
            }
            $routeId = Route::query()->whereIn('code', $mission->route_scope ?? [])->where('is_demo', false)->value('id');
            $session = TransportSurveySession::query()->create([
                'stable_id' => 'field-session-'.substr(hash('sha256', $mission->stable_id.'|'.$operator->getKey().'|'.now()->format('Y-m-d')), 0, 32),
                'field_mission_id' => $mission->getKey(),
                'title' => $mission->title.' · field collection',
                'purpose' => 'Bounded field verification for mission '.$mission->stable_id.'.',
                'city_id' => $cityId,
                'route_id' => $routeId,
                'observer_user_id' => $operator->getKey(),
                'observer_reference' => 'field-operator-'.$operator->getKey(),
                'status' => 'IN_PROGRESS',
                'is_synthetic' => false,
                'started_at' => now(),
                'import_hash' => hash('sha256', $mission->stable_id.'|'.$operator->getKey()),
            ]);
            $mission->forceFill(['status' => FieldMissionStatus::IN_PROGRESS])->save();
            $this->action('SESSION_STARTED', $mission, null, null, $operator, null, FieldMissionStatus::PLANNED->value, FieldMissionStatus::IN_PROGRESS->value);

            return $session;
        });
    }

    /** @param array<string, mixed> $payload @return array{observation: FieldObservation, idempotent: bool} */
    public function submitObservation(TransportSurveySession $session, array $payload, User $operator): array
    {
        return DB::transaction(function () use ($session, $payload, $operator): array {
            $session = TransportSurveySession::query()->with('mission')->lockForUpdate()->findOrFail($session->getKey());
            $this->assertSessionAccess($session, $operator);
            $claim = FieldMissionClaim::query()->where('stable_id', (string) ($payload['claim_id'] ?? ''))->lockForUpdate()->first();
            if ($claim === null || (int) $claim->field_mission_id !== (int) $session->field_mission_id) {
                throw ValidationException::withMessages(['claim_id' => 'The observation target is not part of this mission.']);
            }
            $this->validateObservationPayload($payload);
            $stableId = trim((string) $payload['stable_id']);
            $existing = FieldObservation::query()->where('stable_id', $stableId)->lockForUpdate()->first();
            $hash = $this->payloadHash($payload);
            if ($existing !== null) {
                if ($existing->import_hash !== $hash || (int) $existing->survey_session_id !== (int) $session->getKey()) {
                    throw ValidationException::withMessages(['stable_id' => 'This client submission ID already contains different data. Resolve the sync conflict manually.']);
                }

                return ['observation' => $existing, 'idempotent' => true];
            }
            $sequence = (int) FieldObservation::query()->where('survey_session_id', $session->getKey())->max('sequence') + 1;
            $fact = $this->cleanJson($payload['fact']);
            $observedPrecision = (string) ($fact['observed_precision'] ?? 'COULD_NOT_CONFIRM');
            $location = $payload['location'] ?? null;
            if ($location !== null) {
                $location = ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
            }
            $locationPrecision = $this->storagePrecision($observedPrecision, $payload['accuracy_m'] ?? null, $location !== null);
            $observation = FieldObservation::query()->create([
                'stable_id' => $stableId,
                'survey_session_id' => $session->getKey(),
                'field_mission_claim_id' => $claim->getKey(),
                'sequence' => $sequence,
                'observation_type' => (string) $payload['type'],
                'route_id' => $this->targetId($claim, Route::class),
                'route_pattern_id' => $this->targetId($claim, RoutePattern::class),
                'stop_id' => $this->targetId($claim, Stop::class),
                'observed_at' => $payload['observed_at'],
                'location' => $location,
                'location_method' => $location === null ? 'UNKNOWN' : 'DEVICE',
                'location_precision' => $locationPrecision,
                'reported_accuracy_m' => $payload['accuracy_m'] ?? null,
                'observed_fact' => $fact,
                'notes' => $this->cleanText($payload['notes'] ?? null, 2000),
                'supporting_references' => $this->cleanReferences($payload['supporting_references'] ?? []),
                'review_status' => FieldObservationStatus::SUBMITTED,
                'client_schema_version' => (int) ($payload['schema_version'] ?? 1),
                'import_hash' => $hash,
            ]);
            $claim->forceFill(['current_status' => FieldMissionClaimStatus::OBSERVATION_SUBMITTED])->save();
            $this->action('OBSERVATION_SUBMITTED', $session->mission, $claim, $observation, $operator, null, null, FieldObservationStatus::SUBMITTED->value);

            return ['observation' => $observation, 'idempotent' => false];
        });
    }

    /** @param list<array<string, mixed>> $payloads @return array{accepted: list<string>, idempotent: list<string>, rejected: list<array<string, string>>} */
    public function sync(TransportSurveySession $session, array $payloads, User $operator): array
    {
        $result = ['accepted' => [], 'idempotent' => [], 'rejected' => []];
        foreach (array_slice($payloads, 0, 50) as $payload) {
            try {
                $saved = $this->submitObservation($session, $payload, $operator);
                $stableId = (string) ($payload['stable_id'] ?? 'unknown');
                $result[$saved['idempotent'] ? 'idempotent' : 'accepted'][] = $stableId;
            } catch (ValidationException $exception) {
                $result['rejected'][] = ['stable_id' => (string) ($payload['stable_id'] ?? 'unknown'), 'message' => 'The observation failed bounded validation.'];
            }
        }

        return $result;
    }

    public function submitSession(TransportSurveySession $session, User $operator): TransportSurveySession
    {
        return DB::transaction(function () use ($session, $operator): TransportSurveySession {
            $session = TransportSurveySession::query()->with('mission')->lockForUpdate()->findOrFail($session->getKey());
            $this->assertSessionAccess($session, $operator);
            if (! in_array($session->status?->value ?? $session->status, ['IN_PROGRESS', 'SUBMITTED'], true)) {
                throw ValidationException::withMessages(['session' => 'Only an active field session can be submitted.']);
            }
            $from = $session->status?->value ?? $session->status;
            $session->forceFill(['status' => 'SUBMITTED', 'ended_at' => now()])->save();
            $session->mission?->forceFill(['status' => FieldMissionStatus::SUBMITTED])->save();
            $this->action('SESSION_SUBMITTED', $session->mission, null, null, $operator, null, $from, 'SUBMITTED');

            return $session->refresh();
        });
    }

    private function validateObservationPayload(array $payload): void
    {
        $allowed = FieldObservationType::values();
        $type = $payload['type'] ?? null;
        if (! is_string($payload['stable_id'] ?? null) || preg_match('/^[A-Za-z0-9._:-]{8,150}$/', $payload['stable_id']) !== 1) {
            throw ValidationException::withMessages(['stable_id' => 'Use a bounded client submission ID.']);
        }
        if (! is_string($type) || ! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages(['type' => 'This observation type is not supported.']);
        }
        try {
            $observedAt = Carbon::parse((string) ($payload['observed_at'] ?? ''));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['observed_at' => 'Observation time is invalid.']);
        }
        if ($observedAt->lt(now()->subDays(30)) || $observedAt->gt(now()->addMinutes(10))) {
            throw ValidationException::withMessages(['observed_at' => 'Observation time is outside the bounded field window.']);
        }
        if (! is_array($payload['fact'] ?? null) || array_is_list($payload['fact']) || $payload['fact'] === []) {
            throw ValidationException::withMessages(['fact' => 'A structured observation fact is required.']);
        }
        if (strlen(json_encode($payload['fact'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') > 12000) {
            throw ValidationException::withMessages(['fact' => 'Observation facts are too large.']);
        }
        if (isset($payload['notes']) && (! is_string($payload['notes']) || mb_strlen($payload['notes']) > 2000)) {
            throw ValidationException::withMessages(['notes' => 'Notes must be at most 2000 characters.']);
        }
        if (isset($payload['accuracy_m']) && (! is_numeric($payload['accuracy_m']) || (float) $payload['accuracy_m'] < 0 || (float) $payload['accuracy_m'] > 5000)) {
            throw ValidationException::withMessages(['accuracy_m' => 'Accuracy must be between 0 and 5000 metres.']);
        }
        if (isset($payload['location'])) {
            $location = $payload['location'];
            if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
                throw ValidationException::withMessages(['location' => 'Location must contain latitude and longitude.']);
            }
            try {
                Geo::assertForField((float) $location['lat'], (float) $location['lng']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['location' => 'Location is outside WGS84 bounds.']);
            }
        }
    }

    private function assertSessionAccess(TransportSurveySession $session, User $operator): void
    {
        if ($session->is_synthetic || (int) $session->observer_user_id !== (int) $operator->getKey()) {
            throw ValidationException::withMessages(['session' => 'This field session is not assigned to the authenticated field operator.']);
        }
    }

    private function storagePrecision(string $observedPrecision, mixed $accuracy, bool $hasLocation): string
    {
        if (! $hasLocation && $observedPrecision === 'EXACT_POINT_OBSERVED') {
            return 'UNKNOWN';
        }
        if ((float) ($accuracy ?? 0) > 50 && $observedPrecision === 'EXACT_POINT_OBSERVED') {
            return 'GENERAL_AREA';
        }

        return match ($observedPrecision) {
            'EXACT_POINT_OBSERVED' => 'POINT_ESTIMATE',
            'BOARDING_AREA_OBSERVED' => 'BOARDING_AREA',
            'APPROXIMATE_ONLY' => 'GENERAL_AREA',
            default => 'UNKNOWN',
        };
    }

    private function targetId(FieldMissionClaim $claim, string $class): ?int
    {
        return match ($claim->target_type) {
            'route' => $class === Route::class ? $this->modelId($class, $claim->target_stable_id) : null,
            'route_pattern' => $class === RoutePattern::class ? $this->modelId($class, $claim->target_stable_id) : null,
            'route_pattern_stop' => $class === Stop::class ? $this->modelId($class, $claim->metadata['stop_stable_id'] ?? null) : ($class === RoutePattern::class ? $this->modelId($class, $claim->metadata['pattern_stable_id'] ?? null) : null),
            default => null,
        };
    }

    private function modelId(string $class, mixed $stableId): ?int
    {
        return is_string($stableId) && $stableId !== '' ? $class::query()->where('stable_id', $stableId)->value('id') : null;
    }

    /** @return array<string, mixed> */
    private function claimAttributes(FieldMission $mission, array $claim): array
    {
        $entity = is_array($claim['target_entity'] ?? null) ? $claim['target_entity'] : [];
        $type = (string) ($claim['claim_type'] ?? 'OTHER');
        $targetType = match ($type) {
            'PATTERN_SERVICE' => 'route_pattern',
            'TRANSFER' => 'transfer_relationship',
            default => 'route_pattern_stop',
        };
        $targetStableId = $type === 'TRANSFER'
            ? (string) ($entity['transfer_stable_id'] ?? $claim['claim_id'])
            : (string) ($type === 'PATTERN_SERVICE' ? ($entity['pattern_stable_id'] ?? $claim['claim_id']) : ($entity['pattern_stable_id'] ?? '').':'.($entity['stop_stable_id'] ?? 'unknown'));
        $targetId = $this->resolveTargetId($targetType, $targetStableId, $entity);
        if ($targetType === 'route_pattern_stop' && $targetId !== null) {
            $targetStableId = (string) (DB::table('route_pattern_stops')->where('id', $targetId)->value('stable_id') ?: $targetStableId);
        }

        return [
            'stable_id' => (string) $claim['claim_id'],
            'field_mission_id' => $mission->getKey(),
            'claim_type' => $type,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_stable_id' => $targetStableId,
            'prompt' => $this->prompt($type, $entity),
            'current_status' => FieldMissionClaimStatus::OPEN,
            'affected_od_pairs' => array_values(array_unique(array_map('strval', $claim['affected_od_pairs'] ?? []))),
            'journeys_affected' => (int) ($claim['journeys_affected'] ?? 0),
            'priority_score' => (int) ($claim['priority_score'] ?? 0),
            'metadata' => [
                'target_entity' => $entity,
                'research_status' => $claim['research_status'] ?? null,
                'known_sources' => $claim['known_sources'] ?? [],
            ],
        ];
    }

    private function resolveTargetId(string $targetType, string $stableId, array $entity): ?int
    {
        return match ($targetType) {
            'route_pattern' => $this->modelId(RoutePattern::class, $stableId),
            'transfer_relationship' => DB::table('transfer_relationships')->where('stable_id', $stableId)->value('id'),
            'route_pattern_stop' => DB::table('route_pattern_stops as rps')
                ->join('route_patterns as rp', 'rp.id', '=', 'rps.route_pattern_id')
                ->join('stops as s', 's.id', '=', 'rps.stop_id')
                ->where('rp.stable_id', $entity['pattern_stable_id'] ?? '')
                ->where('s.stable_id', $entity['stop_stable_id'] ?? '')
                ->value('rps.id'),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function claimSnapshot(FieldMissionClaim $claim): array
    {
        $entity = $claim->metadata['target_entity'] ?? [];

        return [
            'id' => $claim->getKey(),
            'stable_id' => $claim->stable_id,
            'claim_type' => $claim->claim_type,
            'target_type' => $claim->target_type,
            'target_stable_id' => $claim->target_stable_id,
            'prompt' => $claim->prompt,
            'status' => $claim->current_status?->value ?? $claim->current_status,
            'priority_score' => $claim->priority_score,
            'journeys_affected' => $claim->journeys_affected,
            'affected_od_pairs' => $claim->affected_od_pairs ?? [],
            'route_code' => $entity['route_code'] ?? null,
            'direction' => $entity['direction'] ?? null,
            'stop_name' => $entity['stop_name'] ?? null,
            'pattern_stable_id' => $entity['pattern_stable_id'] ?? null,
            'stop_stable_id' => $entity['stop_stable_id'] ?? null,
            'research_status' => $claim->metadata['research_status'] ?? null,
        ];
    }

    private function prompt(string $type, array $entity): string
    {
        $route = trim((string) ($entity['route_code'] ?? '').' '.(string) ($entity['direction'] ?? ''));
        $stop = (string) ($entity['stop_name'] ?? 'the mapped stop');

        return match ($type) {
            'PATTERN_SERVICE' => 'Confirm whether '.$route.' is operating during the observed field window.',
            'SEQUENCE' => 'Observe whether '.$stop.' occurs in the mapped order for '.$route.'.',
            'BOARDING' => 'Confirm the practical boarding area and landmark for '.$route.' near '.$stop.'.',
            'ALIGHTING' => 'Confirm where commuters can request/alight near '.$stop.' for '.$route.'.',
            'TRANSFER' => 'Confirm whether the mapped transfer can be completed in practice, including both boarding areas.',
            default => 'Record the narrow field fact described by this claim without inferring unobserved service.',
        };
    }

    private function missionPriority(array $claims): int
    {
        return min(65535, max(0, (int) collect($claims)->max(fn (array $claim): int => (int) ($claim['priority_score'] ?? 0))));
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->cleanJson($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function cleanText(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(strip_tags($value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @return list<string> */
    private function cleanReferences(mixed $references): array
    {
        if (! is_array($references)) {
            return [];
        }

        return array_values(array_slice(array_filter(array_map(function (mixed $reference): ?string {
            if (! is_string($reference) || mb_strlen($reference) > 500) {
                return null;
            }
            if (str_starts_with($reference, 'ref:')) {
                return preg_match('/^ref:[A-Za-z0-9._:-]{1,480}$/', $reference) === 1 ? $reference : null;
            }

            return filter_var($reference, FILTER_VALIDATE_URL) ? $reference : null;
        }, $references)), 0, 10));
    }

    private function cleanJson(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return '[depth limited]';
        }
        if (is_string($value)) {
            return mb_substr(trim(strip_tags($value)), 0, 1000);
        }
        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? $value : (string) $value;
        }

        $result = [];
        foreach (array_slice($value, 0, 100, true) as $key => $item) {
            $result[is_string($key) ? mb_substr($key, 0, 100) : $key] = $this->cleanJson($item, $depth + 1);
        }

        return $result;
    }

    private function action(
        string $action,
        ?FieldMission $mission,
        ?FieldMissionClaim $claim,
        ?FieldObservation $observation,
        ?User $actor,
        ?string $reason,
        ?string $fromStatus,
        ?string $toStatus,
        array $before = [],
        array $after = [],
    ): FieldReviewAction {
        return FieldReviewAction::query()->create([
            'stable_id' => 'field-action-'.substr(hash('sha256', implode('|', [$action, $mission?->stable_id, $claim?->stable_id, $observation?->stable_id, microtime(true)])), 0, 32),
            'field_mission_id' => $mission?->getKey(),
            'field_mission_claim_id' => $claim?->getKey(),
            'field_observation_id' => $observation?->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'before_snapshot' => $before === [] ? null : $before,
            'after_snapshot' => $after === [] ? null : $after,
        ]);
    }
}
