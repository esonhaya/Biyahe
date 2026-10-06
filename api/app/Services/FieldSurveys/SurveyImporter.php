<?php

namespace App\Services\FieldSurveys;

use App\Enums\FieldObservationStatus;
use App\Enums\SurveySessionStatus;
use App\Models\City;
use App\Models\FieldObservation;
use App\Models\Place;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\Stop;
use App\Models\SurveyTracePoint;
use App\Models\TransportSurveySession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SurveyImporter
{
    public function import(SurveyImportValidationResult $validation, bool $synthetic = false): SurveyImportSummary
    {
        $this->assertValid($validation);

        return DB::transaction(fn (): SurveyImportSummary => $this->apply($validation, $synthetic));
    }

    public function dryRun(SurveyImportValidationResult $validation, bool $synthetic = false): SurveyImportSummary
    {
        $this->assertValid($validation);
        DB::beginTransaction();
        try {
            return $this->apply($validation, $synthetic);
        } finally {
            DB::rollBack();
        }
    }

    private function assertValid(SurveyImportValidationResult $validation): void
    {
        if (! $validation->isValid() || $validation->data === null) {
            throw new RuntimeException('Only a fully validated field-survey package can be imported.');
        }
    }

    private function apply(SurveyImportValidationResult $validation, bool $synthetic): SurveyImportSummary
    {
        $summary = new SurveyImportSummary;
        foreach ($validation->data['surveys'] as $survey) {
            $session = $this->session($survey, $synthetic, $summary);
            $hasNewRecords = false;
            foreach ($survey['observations'] ?? [] as $observation) {
                $hasNewRecords = $this->observation($session, $observation, $survey['status'], $summary) || $hasNewRecords;
            }
            foreach ($survey['trace_points'] ?? [] as $tracePoint) {
                $hasNewRecords = $this->tracePoint($session, $tracePoint, $summary) || $hasNewRecords;
            }
            if ($hasNewRecords && in_array($session->status, [SurveySessionStatus::UNDER_REVIEW, SurveySessionStatus::REVIEWED], true)) {
                throw new SurveyImportConflict("Survey {$session->stable_id} is already under review; import additional evidence in a new session.");
            }
        }

        return $summary;
    }

    /** @param array<string, mixed> $survey */
    private function session(array $survey, bool $synthetic, SurveyImportSummary $summary): TransportSurveySession
    {
        $summary->record('surveys', 'examined');
        $hash = $this->hash($this->sessionIdentityPayload($survey));
        $existing = TransportSurveySession::query()->where('stable_id', $survey['stable_id'])->first();
        if ($existing !== null) {
            if ($existing->import_hash !== $hash || $existing->is_synthetic !== $synthetic) {
                $summary->record('surveys', 'rejected');
                throw new SurveyImportConflict("Stable survey ID {$survey['stable_id']} already exists with different or non-imported content.");
            }
            $this->mergeCollectionMetadata($existing, $survey);
            $this->advanceCollectionStatus($existing, SurveySessionStatus::from($survey['status']));
            $summary->record('surveys', 'skipped');

            return $existing;
        }

        $session = TransportSurveySession::query()->create([
            ...$this->sessionPayload($survey),
            'city_id' => City::query()->where('stable_id', $survey['city_id'])->value('id'),
            'route_id' => $this->idFor(Route::class, $survey['route_id'] ?? null),
            'route_pattern_id' => $this->idFor(RoutePattern::class, $survey['route_pattern_id'] ?? null),
            'observer_user_id' => $survey['observer_user_id'] ?? null,
            'observer_reference' => $survey['observer_reference'],
            'status' => $survey['status'],
            'is_synthetic' => $synthetic,
            'import_hash' => $hash,
        ]);
        $summary->record('surveys', 'imported');

        return $session;
    }

    /** @param array<string, mixed> $survey @return array<string, mixed> */
    private function sessionIdentityPayload(array $survey): array
    {
        return [
            'stable_id' => $survey['stable_id'],
            'title' => $survey['title'],
            'purpose' => $survey['purpose'],
            'city_id' => $survey['city_id'],
            'route_id' => $survey['route_id'] ?? null,
            'route_pattern_id' => $survey['route_pattern_id'] ?? null,
            'observer_reference' => $survey['observer_reference'],
            'observer_user_id' => $survey['observer_user_id'] ?? null,
        ];
    }

    /** @param array<string, mixed> $survey */
    private function mergeCollectionMetadata(TransportSurveySession $session, array $survey): void
    {
        $updates = [];
        foreach (['started_at', 'ended_at'] as $field) {
            $incoming = $survey[$field] ?? null;
            $current = $session->{$field};
            if ($incoming === null) {
                continue;
            }
            if ($current !== null && ! Carbon::parse($current)->equalTo(Carbon::parse($incoming))) {
                throw new SurveyImportConflict("Survey {$session->stable_id} has conflicting {$field} metadata.");
            }
            $updates[$field] = $incoming;
        }
        $incomingNotes = $survey['notes'] ?? null;
        if ($incomingNotes !== null) {
            if ($session->notes !== null && $session->notes !== $incomingNotes) {
                throw new SurveyImportConflict("Survey {$session->stable_id} has conflicting notes; record new details as observations.");
            }
            $updates['notes'] = $incomingNotes;
        }
        if ($updates !== []) {
            $session->forceFill($updates)->save();
        }
    }

    private function advanceCollectionStatus(TransportSurveySession $session, SurveySessionStatus $incoming): void
    {
        $allowed = match ($session->status) {
            SurveySessionStatus::PLANNED => [SurveySessionStatus::IN_PROGRESS, SurveySessionStatus::COMPLETED, SurveySessionStatus::ABORTED],
            SurveySessionStatus::IN_PROGRESS => [SurveySessionStatus::COMPLETED, SurveySessionStatus::ABORTED],
            default => [],
        };
        if (in_array($incoming, $allowed, true)) {
            $session->forceFill(['status' => $incoming])->save();
        }
    }

    /** @param array<string, mixed> $survey */
    private function sessionPayload(array $survey): array
    {
        return [
            ...$this->sessionIdentityPayload($survey),
            'started_at' => $survey['started_at'] ?? null,
            'ended_at' => $survey['ended_at'] ?? null,
            'notes' => $survey['notes'] ?? null,
        ];
    }

    /** @param array<string, mixed> $observation */
    private function observation(TransportSurveySession $session, array $observation, string $surveyStatus, SurveyImportSummary $summary): bool
    {
        $summary->record('observations', 'examined');
        $hash = $this->hash($observation);
        $existing = FieldObservation::query()->where('stable_id', $observation['stable_id'])->first();
        if ($existing !== null) {
            if ($existing->import_hash !== $hash || $existing->survey_session_id !== $session->id) {
                $summary->record('observations', 'rejected');
                throw new SurveyImportConflict("Stable observation ID {$observation['stable_id']} conflicts with stored evidence.");
            }
            $summary->record('observations', 'skipped');

            return false;
        }

        $fact = $observation['fact'];
        $location = $observation['location']['coordinates'] ?? null;
        FieldObservation::query()->create([
            'stable_id' => $observation['stable_id'],
            'survey_session_id' => $session->id,
            'sequence' => $observation['sequence'],
            'observation_type' => $observation['type'],
            'route_id' => $this->idFor(Route::class, $observation['route_id'] ?? null),
            'route_pattern_id' => $this->idFor(RoutePattern::class, $observation['route_pattern_id'] ?? null),
            'stop_id' => $this->idFor(Stop::class, $observation['stop_id'] ?? null),
            'place_id' => $this->idFor(Place::class, $observation['place_id'] ?? null),
            'fare_from_stop_id' => $this->idFor(Stop::class, $observation['fare_from_stop_id'] ?? null),
            'fare_to_stop_id' => $this->idFor(Stop::class, $observation['fare_to_stop_id'] ?? null),
            'observed_at' => $observation['observed_at'],
            'location' => is_array($location) ? ['lat' => (float) $location[1], 'lng' => (float) $location[0]] : null,
            'location_method' => $observation['location_method'] ?? 'UNKNOWN',
            'location_precision' => $observation['location_precision'] ?? 'UNKNOWN',
            'reported_accuracy_m' => $observation['accuracy_m'] ?? null,
            'observed_fact' => $fact,
            'notes' => $observation['notes'] ?? null,
            'fare_amount' => $observation['type'] === 'FARE_PAID' ? $fact['amount'] : null,
            'fare_currency' => $observation['type'] === 'FARE_PAID' ? ($fact['currency'] ?? 'PHP') : null,
            'passenger_class' => $observation['type'] === 'FARE_PAID' ? ($fact['passenger_class'] ?? null) : null,
            'observation_window_minutes' => $observation['window_minutes'] ?? null,
            'supporting_references' => $observation['supporting_references'] ?? [],
            'review_status' => $surveyStatus === SurveySessionStatus::COMPLETED->value
                ? FieldObservationStatus::SUBMITTED
                : FieldObservationStatus::DRAFT,
            'import_hash' => $hash,
        ]);
        $summary->record('observations', 'imported');

        return true;
    }

    /** @param array<string, mixed> $tracePoint */
    private function tracePoint(TransportSurveySession $session, array $tracePoint, SurveyImportSummary $summary): bool
    {
        $summary->record('trace_points', 'examined');
        $hash = $this->hash($tracePoint);
        $existing = SurveyTracePoint::query()->where('stable_id', $tracePoint['stable_id'])->first();
        if ($existing !== null) {
            if ($existing->import_hash !== $hash || $existing->survey_session_id !== $session->id) {
                $summary->record('trace_points', 'rejected');
                throw new SurveyImportConflict("Stable trace-point ID {$tracePoint['stable_id']} conflicts with stored evidence.");
            }
            $summary->record('trace_points', 'skipped');

            return false;
        }

        $coordinates = $tracePoint['location']['coordinates'];
        SurveyTracePoint::query()->create([
            'stable_id' => $tracePoint['stable_id'],
            'survey_session_id' => $session->id,
            'sequence' => $tracePoint['sequence'],
            'observed_at' => $tracePoint['observed_at'] ?? null,
            'location' => ['lat' => (float) $coordinates[1], 'lng' => (float) $coordinates[0]],
            'reported_accuracy_m' => $tracePoint['accuracy_m'] ?? null,
            'location_method' => $tracePoint['location_method'] ?? 'DEVICE',
            'location_precision' => $tracePoint['location_precision'] ?? 'UNKNOWN',
            'import_hash' => $hash,
        ]);
        $summary->record('trace_points', 'imported');

        return true;
    }

    /** @param class-string<Model> $modelClass */
    private function idFor(string $modelClass, ?string $stableId): ?int
    {
        if ($stableId === null) {
            return null;
        }
        $id = $modelClass::query()->where('stable_id', $stableId)->value('id');
        if ($id === null) {
            throw new RuntimeException("Validated survey reference {$stableId} disappeared before import.");
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        $canonical = $this->canonicalize($value);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
