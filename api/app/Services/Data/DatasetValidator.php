<?php

namespace App\Services\Data;

use App\Enums\BoardingPrecision;
use App\Enums\EvidenceClaimType;
use App\Enums\EvidenceConflictStatus;
use App\Enums\TransferReviewStatus;
use App\Enums\VehicleTypeCode;
use App\Enums\VerificationState;
use App\Support\Geo;
use App\Support\SearchText;
use DateTimeImmutable;
use Throwable;

final class DatasetValidator
{
    /** @var list<string> */
    private const COLLECTIONS = ['sources', 'cities', 'places', 'aliases', 'stops', 'operators', 'routes', 'patterns', 'transfers', 'fares'];

    public function validate(string $path): DatasetValidationResult
    {
        if (! is_file($path) || ! is_readable($path)) {
            $result = new DatasetValidationResult($path, null);
            $result->addError('$', 'Dataset file does not exist or is not readable.');

            return $result;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $result = new DatasetValidationResult($path, null);
            $result->addError('$', 'Dataset JSON is invalid: '.$exception->getMessage());

            return $result;
        }

        $result = new DatasetValidationResult($path, is_array($data) ? $data : null);
        if (! is_array($data)) {
            $result->addError('$', 'Dataset root must be a JSON object.');

            return $result;
        }

        $this->validateDocument($data, $result);

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function validateDocument(array $data, DatasetValidationResult $result): void
    {
        if (($data['format'] ?? null) !== 'biyahe-transport-dataset') {
            $result->addError('format', 'Expected format biyahe-transport-dataset.');
        }
        if (($data['version'] ?? null) !== 1) {
            $result->addError('version', 'Only dataset format version 1 is supported.');
        }
        $this->requireString($data, 'dataset_id', 'dataset_id', $result);
        if (! is_array($data['license'] ?? null)) {
            $result->addError('license', 'Dataset license metadata is required.');
        } else {
            $this->requireString($data['license'], 'name', 'license.name', $result);
            $this->requireString($data['license'], 'attribution', 'license.attribution', $result);
        }

        $records = [];
        foreach (self::COLLECTIONS as $collection) {
            if (! is_array($data[$collection] ?? null)) {
                $result->addError($collection, 'Collection must be an array.');
                $records[$collection] = [];

                continue;
            }

            $records[$collection] = $data[$collection];
        }

        $knownIds = [];
        $globalStableIds = [];
        foreach (self::COLLECTIONS as $collection) {
            $knownIds[$collection] = [];
            foreach ($records[$collection] as $recordIndex => $record) {
                $path = $collection.'.'.$recordIndex;
                if (! is_array($record)) {
                    $result->addError($path, 'Record must be a JSON object.');

                    continue;
                }

                $stableId = $record['stable_id'] ?? null;
                if (! is_string($stableId) || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,149}$/', $stableId)) {
                    $result->addError($path.'.stable_id', 'stable_id must use 2-150 lowercase letters, numbers, dots, underscores, or hyphens.');

                    continue;
                }
                if (isset($globalStableIds[$stableId])) {
                    $result->addError($path.'.stable_id', 'Duplicate stable_id also used at '.$globalStableIds[$stableId].'.');
                } else {
                    $globalStableIds[$stableId] = $path;
                }
                if (isset($knownIds[$collection][$stableId])) {
                    $result->addError($path.'.stable_id', 'Duplicate stable_id also used at '.$knownIds[$collection][$stableId].'.');

                    continue;
                }
                $knownIds[$collection][$stableId] = $path;
            }
        }

        foreach ($records['sources'] as $recordIndex => $record) {
            if (is_array($record)) {
                $this->validateSource($record, 'sources.'.$recordIndex, $knownIds['sources'], $result);
            }
        }
        foreach ($records['cities'] as $recordIndex => $record) {
            if (is_array($record)) {
                $this->validateEntityStatus($record, 'cities.'.$recordIndex, $knownIds['sources'], $result);
                $this->requireString($record, 'name', 'cities.'.$recordIndex.'.name', $result);
                $this->requireString($record, 'slug', 'cities.'.$recordIndex.'.slug', $result);
                $this->validatePoint($record['location'] ?? null, 'cities.'.$recordIndex.'.location', $result, false);
            }
        }
        foreach ($records['places'] as $recordIndex => $record) {
            if (is_array($record)) {
                $path = 'places.'.$recordIndex;
                $this->validateEntityStatus($record, $path, $knownIds['sources'], $result);
                $this->requireString($record, 'city_id', $path.'.city_id', $result);
                $this->requireReference($record['city_id'] ?? null, $knownIds['cities'], $path.'.city_id', $result);
                $this->requireString($record, 'name', $path.'.name', $result);
                $this->requireString($record, 'slug', $path.'.slug', $result);
                $this->requireString($record, 'category', $path.'.category', $result);
                $this->validatePoint($record['location'] ?? null, $path.'.location', $result, true);
            }
        }
        $normalizedAliases = [];
        foreach ($records['aliases'] as $recordIndex => $record) {
            if (is_array($record)) {
                $path = 'aliases.'.$recordIndex;
                $this->requireString($record, 'place_id', $path.'.place_id', $result);
                $this->requireReference($record['place_id'] ?? null, $knownIds['places'], $path.'.place_id', $result);
                $this->requireString($record, 'alias', $path.'.alias', $result);
                $this->requireOptionalReference($record['source_id'] ?? null, $knownIds['sources'], $path.'.source_id', $result);
                if (is_string($record['place_id'] ?? null) && is_string($record['alias'] ?? null)) {
                    $normalized = SearchText::normalize($record['alias']);
                    if (isset($normalizedAliases[$record['place_id']][$normalized])) {
                        $result->addError($path.'.alias', 'Duplicate normalized alias for the same place; punctuation and spacing variants must be represented once.');
                    }
                    $normalizedAliases[$record['place_id']][$normalized] = $path;
                }
            }
        }
        foreach ($records['stops'] as $recordIndex => $record) {
            if (is_array($record)) {
                $path = 'stops.'.$recordIndex;
                $this->validateEntityStatus($record, $path, $knownIds['sources'], $result);
                $this->requireString($record, 'city_id', $path.'.city_id', $result);
                $this->requireReference($record['city_id'] ?? null, $knownIds['cities'], $path.'.city_id', $result);
                $this->requireOptionalReference($record['nearby_place_id'] ?? null, $knownIds['places'], $path.'.nearby_place_id', $result);
                $this->requireString($record, 'name', $path.'.name', $result);
                $this->requireString($record, 'code', $path.'.code', $result);
                foreach (['boarding_side', 'direction_note'] as $field) {
                    if (isset($record[$field]) && $record[$field] !== null && (! is_string($record[$field]) || trim($record[$field]) === '')) {
                        $result->addError($path.'.'.$field, $field.' must be a non-empty string when supplied.');
                    }
                }
                if (isset($record['boarding_precision']) && (! is_string($record['boarding_precision']) || ! in_array($record['boarding_precision'], BoardingPrecision::values(), true))) {
                    $result->addError($path.'.boarding_precision', 'boarding_precision is not supported.');
                }
                if (isset($record['boarding_verification_state']) && (! is_string($record['boarding_verification_state']) || ! in_array($record['boarding_verification_state'], VerificationState::values(), true))) {
                    $result->addError($path.'.boarding_verification_state', 'boarding_verification_state must be a valid verification state.');
                }
                $this->requireOptionalReference($record['boarding_source_id'] ?? null, $knownIds['sources'], $path.'.boarding_source_id', $result);
                if (($record['boarding_precision'] ?? null) === BoardingPrecision::EXACT_BOARDING_POINT->value && ($record['boarding_verification_state'] ?? null) !== VerificationState::VERIFIED->value) {
                    $result->addError($path.'.boarding_precision', 'EXACT_BOARDING_POINT requires VERIFIED boarding evidence.');
                }
                $this->validatePoint($record['location'] ?? null, $path.'.location', $result, true);
            }
        }
        foreach ($records['operators'] as $recordIndex => $record) {
            if (is_array($record)) {
                $path = 'operators.'.$recordIndex;
                $this->validateEntityStatus($record, $path, $knownIds['sources'], $result);
                $this->requireString($record, 'name', $path.'.name', $result);
                $this->requireString($record, 'slug', $path.'.slug', $result);
            }
        }
        foreach ($records['routes'] as $recordIndex => $record) {
            if (is_array($record)) {
                $path = 'routes.'.$recordIndex;
                $this->validateEntityStatus($record, $path, $knownIds['sources'], $result);
                $this->requireString($record, 'city_id', $path.'.city_id', $result);
                $this->requireReference($record['city_id'] ?? null, $knownIds['cities'], $path.'.city_id', $result);
                $this->requireOptionalReference($record['operator_id'] ?? null, $knownIds['operators'], $path.'.operator_id', $result);
                $this->requireString($record, 'vehicle_type', $path.'.vehicle_type', $result);
                if (isset($record['vehicle_type']) && ! in_array($record['vehicle_type'], VehicleTypeCode::values(), true)) {
                    $result->addError($path.'.vehicle_type', 'Vehicle type is not supported by the current domain enum.');
                }
                $this->requireString($record, 'code', $path.'.code', $result);
                $this->requireString($record, 'name', $path.'.name', $result);
            }
        }
        foreach ($records['patterns'] as $recordIndex => $record) {
            if (is_array($record)) {
                $this->validatePattern($record, 'patterns.'.$recordIndex, $knownIds, $result);
            }
        }
        $transferPairs = [];
        foreach ($records['transfers'] as $recordIndex => $record) {
            if (is_array($record)) {
                $path = 'transfers.'.$recordIndex;
                $this->validateTransfer($record, $path, $knownIds, $result);
                if (is_string($record['from_stop_id'] ?? null) && is_string($record['to_stop_id'] ?? null)) {
                    $pair = $record['from_stop_id'].':'.$record['to_stop_id'];
                    if (isset($transferPairs[$pair])) {
                        $result->addError($path.'.to_stop_id', 'Duplicate transfer relationship also used at '.$transferPairs[$pair].'.');
                    } else {
                        $transferPairs[$pair] = $path;
                    }
                }
            }
        }
        foreach ($records['fares'] as $recordIndex => $record) {
            if (is_array($record)) {
                $this->validateFare($record, 'fares.'.$recordIndex, $knownIds, $result);
            }
        }

        $this->validateVerifiedClaimScopes($records, $result);
        $this->validateSupersessionCycles($records['sources'], $result);
    }

    /** @param array<string, mixed> $record @param array<string, string> $sourceIds */
    private function validateSource(array $record, string $path, array $sourceIds, DatasetValidationResult $result): void
    {
        $this->requireString($record, 'source_type', $path.'.source_type', $result);
        $sourceTypes = array_keys((array) config('biyahe.evidence.source_quality', []));
        if (is_string($record['source_type'] ?? null) && ! in_array($record['source_type'], $sourceTypes, true)) {
            $result->addError($path.'.source_type', 'source_type is not defined by the source-quality policy.');
        }
        $this->requireString($record, 'title', $path.'.title', $result);
        $this->requireString($record, 'checked_at', $path.'.checked_at', $result);
        $this->validateDate($record['checked_at'] ?? null, $path.'.checked_at', $result);
        $this->requireString($record, 'verified_by', $path.'.verified_by', $result);
        if (isset($record['url']) && $record['url'] !== null && (! is_string($record['url']) || filter_var($record['url'], FILTER_VALIDATE_URL) === false)) {
            $result->addError($path.'.url', 'url must be a valid URL when supplied.');
        }
        if (isset($record['effective_from'])) {
            $this->validateDate($record['effective_from'], $path.'.effective_from', $result);
        }
        if (isset($record['effective_until'])) {
            $this->validateDate($record['effective_until'], $path.'.effective_until', $result);
        }
        foreach (['published_at', 'observed_at'] as $field) {
            if (isset($record[$field])) {
                $this->validateDate($record[$field], $path.'.'.$field, $result);
            }
        }
        if (isset($record['evidence_scope']) && ! is_array($record['evidence_scope'])) {
            $result->addError($path.'.evidence_scope', 'evidence_scope must be an array when supplied.');
        } elseif (isset($record['evidence_scope'])) {
            foreach ($record['evidence_scope'] as $scope) {
                if (! is_string($scope) || ! in_array($scope, EvidenceClaimType::values(), true)) {
                    $result->addError($path.'.evidence_scope', 'evidence_scope contains an unsupported fact scope.');
                    break;
                }
            }
            $stringScopes = array_values(array_filter($record['evidence_scope'], 'is_string'));
            if (count(array_unique($stringScopes)) !== count($stringScopes)) {
                $result->addError($path.'.evidence_scope', 'evidence_scope must not contain duplicates.');
            }
        }
        if (isset($record['conflict_status']) && (! is_string($record['conflict_status']) || ! in_array($record['conflict_status'], EvidenceConflictStatus::values(), true))) {
            $result->addError($path.'.conflict_status', 'conflict_status is not supported.');
        }
        if (($record['conflict_status'] ?? EvidenceConflictStatus::NONE->value) !== EvidenceConflictStatus::NONE->value && (! is_string($record['conflict_group'] ?? null) || trim($record['conflict_group']) === '')) {
            $result->addError($path.'.conflict_group', 'conflict_group is required when evidence is in conflict.');
        }
        $this->requireOptionalReference($record['superseded_by_source_id'] ?? null, $sourceIds, $path.'.superseded_by_source_id', $result);
        if (($record['superseded_by_source_id'] ?? null) === ($record['stable_id'] ?? null)) {
            $result->addError($path.'.superseded_by_source_id', 'A provenance source cannot supersede itself.');
        }
    }

    /** @param array<string, mixed> $record @param array<string, string> $sourceIds */
    private function validateEntityStatus(array $record, string $path, array $sourceIds, DatasetValidationResult $result): void
    {
        $state = $record['verification_state'] ?? null;
        if (! is_string($state) || ! in_array($state, VerificationState::values(), true)) {
            $result->addError($path.'.verification_state', 'verification_state must be DRAFT, UNVERIFIED, VERIFIED, STALE, or RETIRED.');
        }
        if (! is_bool($record['is_demo'] ?? null)) {
            $result->addError($path.'.is_demo', 'is_demo must be boolean.');
        }
        if (($record['is_demo'] ?? false) && $state === VerificationState::VERIFIED->value) {
            $result->addError($path.'.verification_state', 'Demo records cannot be VERIFIED.');
        }
        if (! ($record['is_demo'] ?? false) && ! isset($record['source_id'])) {
            $result->addError($path.'.source_id', 'Non-demo records require provenance.');
        }
        $this->requireOptionalReference($record['source_id'] ?? null, $sourceIds, $path.'.source_id', $result);
    }

    /** @param array<string, mixed> $record @param array<string, array<string, string>> $knownIds */
    private function validatePattern(array $record, string $path, array $knownIds, DatasetValidationResult $result): void
    {
        $this->validateEntityStatus($record, $path, $knownIds['sources'], $result);
        $this->requireString($record, 'route_id', $path.'.route_id', $result);
        $this->requireReference($record['route_id'] ?? null, $knownIds['routes'], $path.'.route_id', $result);
        $this->requireString($record, 'name', $path.'.name', $result);
        $this->requireString($record, 'direction', $path.'.direction', $result);
        try {
            Geo::multiLineStringWkt($record['geometry'] ?? []);
        } catch (Throwable $exception) {
            $result->addError($path.'.geometry', $exception->getMessage());
        }

        if (! is_array($record['stops'] ?? null) || count($record['stops']) < 2) {
            $result->addError($path.'.stops', 'Each active route pattern needs at least two ordered stops.');

            return;
        }

        $sequences = [];
        $stopIds = [];
        foreach ($record['stops'] as $stopIndex => $stop) {
            $stopPath = $path.'.stops.'.$stopIndex;
            if (! is_array($stop)) {
                $result->addError($stopPath, 'Pattern stop must be an object.');

                continue;
            }
            $this->requireString($stop, 'stable_id', $stopPath.'.stable_id', $result);
            $this->requireString($stop, 'stop_id', $stopPath.'.stop_id', $result);
            $this->requireReference($stop['stop_id'] ?? null, $knownIds['stops'], $stopPath.'.stop_id', $result);
            if (! is_int($stop['sequence'] ?? null) || $stop['sequence'] < 1) {
                $result->addError($stopPath.'.sequence', 'sequence must be a positive integer.');
            } elseif (isset($sequences[$stop['sequence']])) {
                $result->addError($stopPath.'.sequence', 'Duplicate sequence position.');
            } else {
                $sequences[$stop['sequence']] = true;
            }
            if (isset($stop['stop_id']) && isset($stopIds[$stop['stop_id']])) {
                $result->addError($stopPath.'.stop_id', 'A pattern cannot list the same stop more than once.');
            }
            if (isset($stop['stop_id'])) {
                $stopIds[$stop['stop_id']] = true;
            }
            if (isset($stop['verification_state']) && (! is_string($stop['verification_state']) || ! in_array($stop['verification_state'], VerificationState::values(), true))) {
                $result->addError($stopPath.'.verification_state', 'verification_state must be DRAFT, UNVERIFIED, VERIFIED, STALE, or RETIRED when supplied.');
            }
            if (($stop['verification_state'] ?? null) === VerificationState::VERIFIED->value && ! isset($stop['source_id']) && ! isset($record['source_id'])) {
                $result->addError($stopPath.'.source_id', 'VERIFIED pattern-stop associations require provenance.');
            }
            $this->requireOptionalReference($stop['source_id'] ?? null, $knownIds['sources'], $stopPath.'.source_id', $result);
            $factStates = [];
            foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
                $stateKey = $fact.'_verification_state';
                $sourceKey = $fact.'_source_id';
                $state = $stop[$stateKey] ?? null;
                if ($state !== null && (! is_string($state) || ! in_array($state, VerificationState::values(), true))) {
                    $result->addError($stopPath.'.'.$stateKey, $stateKey.' must be a valid verification state when supplied.');
                }
                $this->requireOptionalReference($stop[$sourceKey] ?? null, $knownIds['sources'], $stopPath.'.'.$sourceKey, $result);
                if ($state === VerificationState::VERIFIED->value && ! isset($stop[$sourceKey]) && ! isset($stop['source_id']) && ! isset($record['source_id'])) {
                    $result->addError($stopPath.'.'.$sourceKey, 'VERIFIED pattern-stop facts require provenance.');
                }
                $factStates[$fact] = $state;
            }
            if (($stop['verification_state'] ?? null) === VerificationState::VERIFIED->value && count(array_filter($factStates, static fn (?string $state): bool => $state === VerificationState::VERIFIED->value)) !== 4) {
                $result->addError($stopPath.'.verification_state', 'A VERIFIED pattern-stop association must have VERIFIED service, sequence, boarding, and alighting facts.');
            }
        }
        ksort($sequences);
        if (array_keys($sequences) !== range(1, count($sequences))) {
            $result->addError($path.'.stops', 'Pattern stop sequences must be contiguous starting at 1.');
        }
    }

    /** @param array<string, mixed> $record @param array<string, array<string, string>> $knownIds */
    private function validateFare(array $record, string $path, array $knownIds, DatasetValidationResult $result): void
    {
        $this->validateEntityStatus($record, $path, $knownIds['sources'], $result);
        $scope = $record['applicability_scope'] ?? 'ROUTE_SPECIFIC';
        if (! in_array($scope, ['ROUTE_SPECIFIC', 'VEHICLE_CLASS'], true)) {
            $result->addError($path.'.applicability_scope', 'applicability_scope must be ROUTE_SPECIFIC or VEHICLE_CLASS.');
        }
        if ($scope === 'ROUTE_SPECIFIC') {
            $this->requireString($record, 'route_id', $path.'.route_id', $result);
            $this->requireReference($record['route_id'] ?? null, $knownIds['routes'], $path.'.route_id', $result);
        } elseif (($record['route_id'] ?? null) !== null) {
            $result->addError($path.'.route_id', 'VEHICLE_CLASS fare rules must not claim a specific route.');
        }
        $this->requireString($record, 'vehicle_type', $path.'.vehicle_type', $result);
        if (! is_numeric($record['amount'] ?? null) || (float) $record['amount'] < 0) {
            $result->addError($path.'.amount', 'amount must be a non-negative number.');
        }
        $this->requireString($record, 'effective_from', $path.'.effective_from', $result);
        $this->validateDate($record['effective_from'] ?? null, $path.'.effective_from', $result);
        if (isset($record['effective_until'])) {
            $this->validateDate($record['effective_until'], $path.'.effective_until', $result);
            if (is_string($record['effective_from'] ?? null) && is_string($record['effective_until']) && $record['effective_until'] < $record['effective_from']) {
                $result->addError($path.'.effective_until', 'effective_until cannot precede effective_from.');
            }
        }
    }

    /** @param array<string, list<array<string, mixed>>> $records */
    private function validateVerifiedClaimScopes(array $records, DatasetValidationResult $result): void
    {
        $sources = collect($records['sources'])
            ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['stable_id'] ?? null))
            ->keyBy('stable_id');
        $check = function (array $record, mixed $sourceId, string $scope, string $path) use ($sources, $result): void {
            if (($record['verification_state'] ?? null) !== VerificationState::VERIFIED->value) {
                return;
            }
            $source = is_string($sourceId) ? $sources->get($sourceId) : null;
            if (! is_array($source) || ! in_array($scope, $source['evidence_scope'] ?? [], true)) {
                $result->addError($path.'.source_id', 'VERIFIED claim requires provenance scoped to '.$scope.'.');
            }
        };

        foreach ([
            'places' => 'PLACE_LOCATION',
            'stops' => 'STOP_LOCATION',
            'routes' => 'ROUTE_IDENTITY',
            'patterns' => 'ROUTE_PATTERN',
            'transfers' => 'TRANSFER',
        ] as $collection => $scope) {
            foreach ($records[$collection] as $index => $record) {
                if (! is_array($record)) {
                    continue;
                }
                $check($record, $record['source_id'] ?? null, $scope, $collection.'.'.$index);
            }
        }

        foreach ($records['stops'] as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }
            if (($stop['boarding_verification_state'] ?? null) !== VerificationState::VERIFIED->value) {
                continue;
            }
            $sourceId = $stop['boarding_source_id'] ?? $stop['source_id'] ?? null;
            $source = is_string($sourceId) ? $sources->get($sourceId) : null;
            if (! is_array($source) || ! in_array('BOARDING', $source['evidence_scope'] ?? [], true)) {
                $result->addError('stops.'.$index.'.boarding_source_id', 'VERIFIED boarding guidance requires provenance scoped to BOARDING.');
            }
        }

        foreach ($records['patterns'] as $patternIndex => $pattern) {
            if (! is_array($pattern)) {
                continue;
            }
            foreach ($pattern['stops'] ?? [] as $stopIndex => $association) {
                if (! is_array($association)) {
                    continue;
                }
                foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
                    if (($association[$fact.'_verification_state'] ?? null) !== VerificationState::VERIFIED->value) {
                        continue;
                    }
                    $sourceId = $association[$fact.'_source_id'] ?? $association['source_id'] ?? $pattern['source_id'] ?? null;
                    $source = is_string($sourceId) ? $sources->get($sourceId) : null;
                    $scope = 'PATTERN_STOP_'.strtoupper($fact);
                    if (! is_array($source) || ! in_array($scope, $source['evidence_scope'] ?? [], true)) {
                        $result->addError(
                            'patterns.'.$patternIndex.'.stops.'.$stopIndex.'.'.$fact.'_source_id',
                            'VERIFIED pattern-stop facts require provenance scoped to '.$scope.'.',
                        );
                    }
                }
            }
        }

        foreach ($records['fares'] as $index => $fare) {
            $scope = ($fare['applicability_scope'] ?? 'ROUTE_SPECIFIC') === 'VEHICLE_CLASS'
                ? EvidenceClaimType::FARE_GENERAL_TRADITIONAL_PUJ->value
                : EvidenceClaimType::FARE->value;
            $check($fare, $fare['source_id'] ?? null, $scope, 'fares.'.$index);
        }
    }

    /** @param list<array<string, mixed>> $sources */
    private function validateSupersessionCycles(array $sources, DatasetValidationResult $result): void
    {
        $supersedes = collect($sources)
            ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['stable_id'] ?? null))
            ->mapWithKeys(fn (array $source): array => [$source['stable_id'] => $source['superseded_by_source_id'] ?? null]);
        foreach ($supersedes as $stableId => $nextId) {
            $seen = [];
            while (is_string($nextId)) {
                if ($nextId === $stableId || isset($seen[$nextId])) {
                    $result->addError('sources', 'Provenance supersession links must not contain cycles.');
                    break;
                }
                $seen[$nextId] = true;
                $nextId = $supersedes->get($nextId);
            }
        }
    }

    /** @param array<string, mixed> $record @param array<string, array<string, string>> $knownIds */
    private function validateTransfer(array $record, string $path, array $knownIds, DatasetValidationResult $result): void
    {
        $this->validateEntityStatus($record, $path, $knownIds['sources'], $result);
        $this->requireString($record, 'city_id', $path.'.city_id', $result);
        $this->requireReference($record['city_id'] ?? null, $knownIds['cities'], $path.'.city_id', $result);
        $this->requireString($record, 'from_stop_id', $path.'.from_stop_id', $result);
        $this->requireReference($record['from_stop_id'] ?? null, $knownIds['stops'], $path.'.from_stop_id', $result);
        $this->requireString($record, 'to_stop_id', $path.'.to_stop_id', $result);
        $this->requireReference($record['to_stop_id'] ?? null, $knownIds['stops'], $path.'.to_stop_id', $result);
        if (($record['from_stop_id'] ?? null) === ($record['to_stop_id'] ?? null)) {
            $result->addError($path.'.to_stop_id', 'A transfer relationship must connect two different stops.');
        }
        if (isset($record['review_status']) && (! is_string($record['review_status']) || ! in_array($record['review_status'], TransferReviewStatus::values(), true))) {
            $result->addError($path.'.review_status', 'review_status is not supported.');
        }
        if (isset($record['review_notes']) && (! is_string($record['review_notes']) || trim($record['review_notes']) === '')) {
            $result->addError($path.'.review_notes', 'review_notes must be a non-empty string when supplied.');
        }
        if (($record['review_status'] ?? null) === TransferReviewStatus::REJECTED->value && ! isset($record['review_notes'])) {
            $result->addError($path.'.review_notes', 'Rejected transfer candidates must retain a review reason.');
        }
        $this->requireString($record, 'relationship_type', $path.'.relationship_type', $result);
        if (isset($record['distance_meters']) && (! is_numeric($record['distance_meters']) || (float) $record['distance_meters'] < 0)) {
            $result->addError($path.'.distance_meters', 'distance_meters must be a non-negative number when supplied.');
        }
        $this->requireOptionalReference($record['from_pattern_id'] ?? null, $knownIds['patterns'], $path.'.from_pattern_id', $result);
        $this->requireOptionalReference($record['to_pattern_id'] ?? null, $knownIds['patterns'], $path.'.to_pattern_id', $result);
        foreach (['landmark', 'practical_note'] as $field) {
            if (isset($record[$field]) && $record[$field] !== null && (! is_string($record[$field]) || trim($record[$field]) === '')) {
                $result->addError($path.'.'.$field, $field.' must be a non-empty string when supplied.');
            }
        }
    }

    private function validatePoint(mixed $point, string $path, DatasetValidationResult $result, bool $required): void
    {
        if ($point === null && ! $required) {
            return;
        }
        if (Geo::parsePoint($point) === null) {
            $result->addError($path, 'Point must be {lat, lng} in WGS 84 order.');
        }
    }

    /** @param array<string, mixed> $record */
    private function requireString(array $record, string $key, string $path, DatasetValidationResult $result): void
    {
        if (! is_string($record[$key] ?? null) || trim($record[$key]) === '') {
            $result->addError($path, 'A non-empty string is required.');
        }
    }

    /** @param array<string, string> $knownIds */
    private function requireReference(mixed $value, array $knownIds, string $path, DatasetValidationResult $result): void
    {
        if (! is_string($value) || ! isset($knownIds[$value])) {
            $result->addError($path, 'Reference does not resolve within this dataset.');
        }
    }

    /** @param array<string, string> $knownIds */
    private function requireOptionalReference(mixed $value, array $knownIds, string $path, DatasetValidationResult $result): void
    {
        if ($value !== null) {
            $this->requireReference($value, $knownIds, $path, $result);
        }
    }

    private function validateDate(mixed $value, string $path, DatasetValidationResult $result): void
    {
        if (! is_string($value) || DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) {
            $result->addError($path, 'Date must use YYYY-MM-DD.');
        }
    }
}
