<?php

namespace App\Services\Data;

use App\Enums\VehicleTypeCode;
use App\Enums\VerificationState;
use App\Support\Geo;
use DateTimeImmutable;
use Throwable;

final class DatasetValidator
{
    /** @var list<string> */
    private const COLLECTIONS = ['sources', 'cities', 'places', 'aliases', 'stops', 'operators', 'routes', 'patterns', 'fares'];

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
                $this->validateSource($record, 'sources.'.$recordIndex, $result);
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
        foreach ($records['aliases'] as $recordIndex => $record) {
            if (is_array($record)) {
                $path = 'aliases.'.$recordIndex;
                $this->requireString($record, 'place_id', $path.'.place_id', $result);
                $this->requireReference($record['place_id'] ?? null, $knownIds['places'], $path.'.place_id', $result);
                $this->requireString($record, 'alias', $path.'.alias', $result);
                $this->requireOptionalReference($record['source_id'] ?? null, $knownIds['sources'], $path.'.source_id', $result);
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
        foreach ($records['fares'] as $recordIndex => $record) {
            if (is_array($record)) {
                $this->validateFare($record, 'fares.'.$recordIndex, $knownIds, $result);
            }
        }
    }

    /** @param array<string, mixed> $record */
    private function validateSource(array $record, string $path, DatasetValidationResult $result): void
    {
        $this->requireString($record, 'source_type', $path.'.source_type', $result);
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
            $this->requireOptionalReference($stop['source_id'] ?? null, $knownIds['sources'], $stopPath.'.source_id', $result);
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
        $this->requireString($record, 'route_id', $path.'.route_id', $result);
        $this->requireReference($record['route_id'] ?? null, $knownIds['routes'], $path.'.route_id', $result);
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
