<?php

namespace App\Services\FieldSurveys;

use App\Enums\FieldLocationMethod;
use App\Enums\FieldLocationPrecision;
use App\Enums\FieldObservationType;
use App\Enums\SurveySessionStatus;
use App\Models\City;
use App\Models\Place;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\Stop;
use App\Models\User;
use DateTimeImmutable;
use Throwable;

final class SurveyImportValidator
{
    private const MAX_BYTES = 5_242_880;

    private const MAX_SURVEYS = 20;

    private const MAX_OBSERVATIONS = 1000;

    private const MAX_TRACE_POINTS = 2000;

    /** @var list<array{path: string, message: string}> */
    private array $errors = [];

    /** @var array<string, int> */
    private array $cities = [];

    /** @var array<string, array{id: int, city_id: int}> */
    private array $routes = [];

    /** @var array<string, array{id: int, route_id: int, city_id: int}> */
    private array $patterns = [];

    /** @var array<string, array{id: int, city_id: int}> */
    private array $stops = [];

    /** @var array<string, array{id: int, city_id: int}> */
    private array $places = [];

    /** @var array<int, true> */
    private array $users = [];

    public function validate(string $path): SurveyImportValidationResult
    {
        $this->errors = [];
        if (! is_file($path) || ! is_readable($path)) {
            return new SurveyImportValidationResult(null, [['path' => '$', 'message' => 'Survey dataset cannot be read.']], 0);
        }
        if (filesize($path) > self::MAX_BYTES) {
            return new SurveyImportValidationResult(null, [['path' => '$', 'message' => 'Survey dataset exceeds the 5 MiB limit.']], 0);
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return new SurveyImportValidationResult(null, [['path' => '$', 'message' => 'Survey dataset must be valid JSON.']], 0);
        }

        if (! is_array($data) || array_is_list($data)) {
            return new SurveyImportValidationResult(null, [['path' => '$', 'message' => 'Survey dataset root must be a JSON object.']], 0);
        }

        $this->onlyKeys($data, ['schema_version', 'surveys'], '$');
        if (($data['schema_version'] ?? null) !== 1) {
            $this->error('schema_version', 'Only field-survey schema version 1 is supported.');
        }
        if (! is_array($data['surveys'] ?? null) || ! array_is_list($data['surveys'])) {
            $this->error('surveys', 'Surveys must be a JSON array.');
            $surveys = [];
        } else {
            $surveys = $data['surveys'];
            if (count($surveys) > self::MAX_SURVEYS) {
                $this->error('surveys', 'A package may contain no more than 20 surveys.');
            }
        }

        $this->loadReferenceMaps($surveys);
        $surveyIds = [];
        $observationIds = [];
        $traceIds = [];
        $recordsExamined = 0;
        foreach ($surveys as $index => $survey) {
            $recordsExamined++;
            $path = "surveys.{$index}";
            if (! is_array($survey) || array_is_list($survey)) {
                $this->error($path, 'Survey entries must be JSON objects.');

                continue;
            }
            $this->validateSurvey($survey, $path, $surveyIds, $observationIds, $traceIds, $recordsExamined);
        }

        return new SurveyImportValidationResult($data, $this->errors, $recordsExamined);
    }

    /** @param array<string, mixed> $survey @param array<string, true> $surveyIds @param array<string, true> $observationIds @param array<string, true> $traceIds */
    private function validateSurvey(array $survey, string $path, array &$surveyIds, array &$observationIds, array &$traceIds, int &$recordsExamined): void
    {
        $this->onlyKeys($survey, [
            'stable_id', 'title', 'purpose', 'city_id', 'route_id', 'route_pattern_id', 'observer_reference',
            'observer_user_id', 'status', 'started_at', 'ended_at', 'notes', 'observations', 'trace_points',
        ], $path);
        $this->stableId($survey['stable_id'] ?? null, $path.'.stable_id', $surveyIds);
        foreach (['title' => 180, 'purpose' => 2000, 'observer_reference' => 150, 'notes' => 2000] as $field => $max) {
            if (isset($survey[$field]) && (! is_string($survey[$field]) || mb_strlen($survey[$field]) > $max)) {
                $this->error($path.'.'.$field, "{$field} must be a string of at most {$max} characters.");
            }
        }
        foreach (['title', 'purpose', 'observer_reference'] as $field) {
            if (! is_string($survey[$field] ?? null) || trim($survey[$field]) === '') {
                $this->error($path.'.'.$field, "{$field} is required.");
            }
        }
        if (is_string($survey['observer_reference'] ?? null)
            && preg_match('/^(REPLACE_|TODO\b|YOUR_)/i', trim($survey['observer_reference'])) === 1) {
            $this->error($path.'.observer_reference', 'Replace the template observer placeholder with an accountable reference before import.');
        }
        if (array_key_exists('observer_user_id', $survey) && $survey['observer_user_id'] !== null
            && (! is_int($survey['observer_user_id']) || ! isset($this->users[$survey['observer_user_id']]))) {
            $this->error($path.'.observer_user_id', 'Observer user ID must reference an existing Biyahe account.');
        }

        $cityId = $this->resolve($survey['city_id'] ?? null, $this->cities, $path.'.city_id', 'city');
        $route = $this->resolveRecord($survey['route_id'] ?? null, $this->routes, $path.'.route_id', 'route');
        $pattern = $this->resolveRecord($survey['route_pattern_id'] ?? null, $this->patterns, $path.'.route_pattern_id', 'route pattern');
        if ($pattern !== null && $route !== null && $pattern['route_id'] !== $route['id']) {
            $this->error($path.'.route_pattern_id', 'The route pattern does not belong to the selected route.');
        }
        if ($pattern !== null && $cityId !== null && $pattern['city_id'] !== $cityId) {
            $this->error($path.'.route_pattern_id', 'The route pattern must belong to the survey city.');
        }
        if ($route !== null && $cityId !== null && $route['city_id'] !== $cityId) {
            $this->error($path.'.route_id', 'The route must belong to the survey city.');
        }

        $status = $survey['status'] ?? null;
        $importableStatuses = [SurveySessionStatus::PLANNED->value, SurveySessionStatus::IN_PROGRESS->value, SurveySessionStatus::COMPLETED->value, SurveySessionStatus::ABORTED->value];
        if (! is_string($status) || ! in_array($status, $importableStatuses, true)) {
            $this->error($path.'.status', 'Imported surveys may only be PLANNED, IN_PROGRESS, COMPLETED, or ABORTED.');
        }
        $startedAt = $this->timestamp($survey['started_at'] ?? null, $path.'.started_at', false);
        $endedAt = $this->timestamp($survey['ended_at'] ?? null, $path.'.ended_at', false);
        if ($startedAt !== null && $endedAt !== null && $endedAt < $startedAt) {
            $this->error($path.'.ended_at', 'Survey end time must not precede its start time.');
        }
        if ($status === SurveySessionStatus::COMPLETED->value && ($startedAt === null || $endedAt === null)) {
            $this->error($path.'.started_at', 'Completed surveys require both start and end timestamps.');
        }
        if ($status === SurveySessionStatus::ABORTED->value && (($startedAt === null) !== ($endedAt === null))) {
            $this->error($path.'.started_at', 'An aborted survey must have both collection timestamps or neither.');
        }
        if ($status === SurveySessionStatus::IN_PROGRESS->value && ($startedAt === null || $endedAt !== null)) {
            $this->error($path.'.started_at', 'An in-progress survey requires a start time and no end time.');
        }

        $observations = $survey['observations'] ?? [];
        $tracePoints = $survey['trace_points'] ?? [];
        if (! is_array($observations) || ! array_is_list($observations) || count($observations) > self::MAX_OBSERVATIONS) {
            $this->error($path.'.observations', 'Observations must be an array containing at most 1000 entries.');
            $observations = [];
        }
        if (! is_array($tracePoints) || ! array_is_list($tracePoints) || count($tracePoints) > self::MAX_TRACE_POINTS) {
            $this->error($path.'.trace_points', 'Trace points must be an array containing at most 2000 entries.');
            $tracePoints = [];
        }
        if ($status === SurveySessionStatus::COMPLETED->value && $observations === [] && $tracePoints === []) {
            $this->error($path, 'A completed survey must contain at least one observation or trace point.');
        }
        if ($status === SurveySessionStatus::ABORTED->value && $startedAt === null && ($observations !== [] || $tracePoints !== [])) {
            $this->error($path, 'An aborted survey without collection timestamps cannot contain observations or trace points.');
        }
        if ($status === SurveySessionStatus::PLANNED->value
            && ($startedAt !== null || $endedAt !== null || $observations !== [] || $tracePoints !== [])) {
            $this->error($path, 'A planned survey cannot contain field observations, trace points, or collection timestamps.');
        }

        $sequences = [];
        foreach ($observations as $index => $observation) {
            $recordsExamined++;
            $observationPath = $path.'.observations.'.$index;
            if (! is_array($observation) || array_is_list($observation)) {
                $this->error($observationPath, 'Observation entries must be JSON objects.');

                continue;
            }
            $this->validateObservation($observation, $observationPath, $observationIds, $cityId, $startedAt, $endedAt);
            $sequence = $observation['sequence'] ?? null;
            if (is_int($sequence) && $sequence > 0) {
                $sequences[] = $sequence;
            }
        }
        if ($sequences !== []) {
            $sortedSequences = $sequences;
            sort($sortedSequences);
            if ($sequences !== $sortedSequences || $sortedSequences !== range(1, count($sortedSequences))) {
                $this->error($path.'.observations', 'Observation sequence values must be ordered, unique, and contiguous from 1.');
            }
        }

        $traceSequences = [];
        $lastTraceAt = null;
        foreach ($tracePoints as $index => $tracePoint) {
            $recordsExamined++;
            $tracePath = $path.'.trace_points.'.$index;
            if (! is_array($tracePoint) || array_is_list($tracePoint)) {
                $this->error($tracePath, 'Trace point entries must be JSON objects.');

                continue;
            }
            $traceAt = $this->validateTracePoint($tracePoint, $tracePath, $traceIds, $startedAt, $endedAt);
            if ($traceAt !== null && $lastTraceAt !== null && $traceAt < $lastTraceAt) {
                $this->error($tracePath.'.observed_at', 'Trace timestamps must not go backwards in recorded sequence order.');
            }
            if ($traceAt !== null) {
                $lastTraceAt = $traceAt;
            }
            $sequence = $tracePoint['sequence'] ?? null;
            if (is_int($sequence) && $sequence > 0) {
                $traceSequences[] = $sequence;
            }
        }
        if ($traceSequences !== []) {
            $sortedSequences = $traceSequences;
            sort($sortedSequences);
            if ($traceSequences !== $sortedSequences || $sortedSequences !== range(1, count($sortedSequences))) {
                $this->error($path.'.trace_points', 'Trace sequence values must be ordered, unique, and contiguous from 1.');
            }
        }
    }

    /** @param array<string, mixed> $observation @param array<string, true> $ids */
    private function validateObservation(array $observation, string $path, array &$ids, ?int $cityId, ?DateTimeImmutable $startedAt, ?DateTimeImmutable $endedAt): void
    {
        $this->onlyKeys($observation, [
            'stable_id', 'sequence', 'type', 'observed_at', 'route_id', 'route_pattern_id', 'stop_id', 'place_id',
            'fare_from_stop_id', 'fare_to_stop_id', 'location', 'location_method', 'location_precision', 'accuracy_m', 'fact', 'notes', 'window_minutes', 'supporting_references',
        ], $path);
        $this->stableId($observation['stable_id'] ?? null, $path.'.stable_id', $ids);
        if (! is_int($observation['sequence'] ?? null) || $observation['sequence'] < 1) {
            $this->error($path.'.sequence', 'Observation sequence must be a positive integer.');
        }
        $type = $observation['type'] ?? null;
        if (! is_string($type) || ! in_array($type, FieldObservationType::values(), true)) {
            $this->error($path.'.type', 'Observation type is not supported.');
        }
        $observedAt = $this->timestamp($observation['observed_at'] ?? null, $path.'.observed_at');
        if ($observedAt !== null && (($startedAt !== null && $observedAt < $startedAt) || ($endedAt !== null && $observedAt > $endedAt))) {
            $this->error($path.'.observed_at', 'Observation time must fall within the survey interval.');
        }
        $route = $this->resolveRecord($observation['route_id'] ?? null, $this->routes, $path.'.route_id', 'route');
        $pattern = $this->resolveRecord($observation['route_pattern_id'] ?? null, $this->patterns, $path.'.route_pattern_id', 'route pattern');
        if ($route !== null && $cityId !== null && $route['city_id'] !== $cityId) {
            $this->error($path.'.route_id', 'Observed route must belong to the survey city.');
        }
        if ($pattern !== null && $route !== null && $pattern['route_id'] !== $route['id']) {
            $this->error($path.'.route_pattern_id', 'Observed pattern must belong to the observed route.');
        }
        if ($pattern !== null && $cityId !== null && $pattern['city_id'] !== $cityId) {
            $this->error($path.'.route_pattern_id', 'Observed pattern must belong to the survey city.');
        }
        foreach (['stop_id', 'fare_from_stop_id', 'fare_to_stop_id'] as $field) {
            $stop = $this->resolveRecord($observation[$field] ?? null, $this->stops, $path.'.'.$field, 'stop');
            if ($stop !== null && $cityId !== null && $stop['city_id'] !== $cityId) {
                $this->error($path.'.'.$field, 'Observed stop must belong to the survey city.');
            }
        }
        $place = $this->resolveRecord($observation['place_id'] ?? null, $this->places, $path.'.place_id', 'place');
        if ($place !== null && $cityId !== null && $place['city_id'] !== $cityId) {
            $this->error($path.'.place_id', 'Observed place must belong to the survey city.');
        }

        $method = $observation['location_method'] ?? FieldLocationMethod::UNKNOWN->value;
        if (! is_string($method) || ! in_array($method, FieldLocationMethod::values(), true)) {
            $this->error($path.'.location_method', 'Location method must be DEVICE, MANUAL, or UNKNOWN.');
        }
        $precision = $observation['location_precision'] ?? FieldLocationPrecision::UNKNOWN->value;
        if (! is_string($precision) || ! in_array($precision, FieldLocationPrecision::values(), true)) {
            $this->error($path.'.location_precision', 'Location precision must be POINT_ESTIMATE, BOARDING_AREA, LANDMARK, GENERAL_AREA, or UNKNOWN.');
        }
        $hasLocation = $this->validatePoint($observation['location'] ?? null, $path.'.location', false);
        if ($method === FieldLocationMethod::DEVICE->value && ! $hasLocation) {
            $this->error($path.'.location', 'Device-derived location method requires a recorded point.');
        }
        if (array_key_exists('accuracy_m', $observation) && $observation['accuracy_m'] !== null
            && (! is_numeric($observation['accuracy_m']) || (float) $observation['accuracy_m'] < 0 || (float) $observation['accuracy_m'] > 5000)) {
            $this->error($path.'.accuracy_m', 'Reported accuracy must be between 0 and 5000 metres.');
        }
        if (isset($observation['notes']) && (! is_string($observation['notes']) || mb_strlen($observation['notes']) > 2000)) {
            $this->error($path.'.notes', 'Observation notes must be at most 2000 characters.');
        }
        $fact = $observation['fact'] ?? null;
        if (! is_array($fact) || array_is_list($fact) || $fact === []) {
            $this->error($path.'.fact', 'Observed fact must be a non-empty JSON object.');
            $fact = [];
        } else {
            $this->validateFact($type, $fact, $path.'.fact', $route !== null);
        }
        if ($type === FieldObservationType::SERVICE_NOT_OBSERVED->value) {
            $window = $observation['window_minutes'] ?? null;
            if (! is_int($window) || $window < 1 || $window > 720) {
                $this->error($path.'.window_minutes', 'A negative observation needs an observation window from 1 to 720 minutes.');
            }
            if (! $hasLocation) {
                $this->error($path.'.location', 'A negative service observation must identify the watched location.');
            }
        }
        if ($type === FieldObservationType::FARE_PAID->value) {
            $amount = $fact['amount'] ?? null;
            if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 10000) {
                $this->error($path.'.fact.amount', 'Observed fare must be greater than zero and at most 10000.');
            }
            $currency = $fact['currency'] ?? 'PHP';
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $this->error($path.'.fact.currency', 'Fare currency must be a three-letter uppercase code.');
            }
        }

        $references = $observation['supporting_references'] ?? [];
        if (! is_array($references) || ! array_is_list($references) || count($references) > 10) {
            $this->error($path.'.supporting_references', 'Supporting references must be an array of at most 10 items.');
        } else {
            foreach ($references as $index => $reference) {
                $referencePath = $path.'.supporting_references.'.$index;
                if (! is_array($reference) || array_is_list($reference)) {
                    $this->error($referencePath, 'Reference entries must be JSON objects.');

                    continue;
                }
                $this->onlyKeys($reference, ['type', 'title', 'reference', 'captured_at'], $referencePath);
                if (! in_array($reference['type'] ?? null, ['PHOTO', 'SIGNBOARD', 'FARE_MATRIX', 'DOCUMENT', 'OTHER'], true)) {
                    $this->error($referencePath.'.type', 'Supporting reference type is not supported.');
                }
                if (! is_string($reference['title'] ?? null) || mb_strlen($reference['title']) > 180) {
                    $this->error($referencePath.'.title', 'Reference title is required and limited to 180 characters.');
                }
                $identifier = $reference['reference'] ?? null;
                if (! is_string($identifier) || mb_strlen($identifier) > 2048
                    || (! filter_var($identifier, FILTER_VALIDATE_URL) && preg_match('/^ref:[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $identifier) !== 1)
                    || (filter_var($identifier, FILTER_VALIDATE_URL) && parse_url($identifier, PHP_URL_SCHEME) !== 'https')) {
                    $this->error($referencePath.'.reference', 'Use an HTTPS URL or an opaque ref: identifier; local file paths are not accepted.');
                }
                if (isset($reference['captured_at'])) {
                    $this->timestamp($reference['captured_at'], $referencePath.'.captured_at', false);
                }
            }
        }
    }

    /** @param array<string, mixed> $tracePoint @param array<string, true> $ids */
    private function validateTracePoint(array $tracePoint, string $path, array &$ids, ?DateTimeImmutable $startedAt, ?DateTimeImmutable $endedAt): ?DateTimeImmutable
    {
        $this->onlyKeys($tracePoint, ['stable_id', 'sequence', 'observed_at', 'location', 'location_method', 'location_precision', 'accuracy_m'], $path);
        $this->stableId($tracePoint['stable_id'] ?? null, $path.'.stable_id', $ids);
        if (! is_int($tracePoint['sequence'] ?? null) || $tracePoint['sequence'] < 1) {
            $this->error($path.'.sequence', 'Trace sequence must be a positive integer.');
        }
        $this->validatePoint($tracePoint['location'] ?? null, $path.'.location', true);
        $traceAt = $this->timestamp($tracePoint['observed_at'] ?? null, $path.'.observed_at', false);
        $method = $tracePoint['location_method'] ?? FieldLocationMethod::DEVICE->value;
        if (! is_string($method) || ! in_array($method, FieldLocationMethod::values(), true)) {
            $this->error($path.'.location_method', 'Trace location method must be DEVICE, MANUAL, or UNKNOWN.');
        }
        $precision = $tracePoint['location_precision'] ?? FieldLocationPrecision::UNKNOWN->value;
        if (! is_string($precision) || ! in_array($precision, FieldLocationPrecision::values(), true)) {
            $this->error($path.'.location_precision', 'Location precision must be POINT_ESTIMATE, BOARDING_AREA, LANDMARK, GENERAL_AREA, or UNKNOWN.');
        }
        if (array_key_exists('accuracy_m', $tracePoint) && $tracePoint['accuracy_m'] !== null
            && (! is_numeric($tracePoint['accuracy_m']) || (float) $tracePoint['accuracy_m'] < 0 || (float) $tracePoint['accuracy_m'] > 5000)) {
            $this->error($path.'.accuracy_m', 'Reported accuracy must be between 0 and 5000 metres.');
        }
        if ($traceAt !== null && (($startedAt !== null && $traceAt < $startedAt) || ($endedAt !== null && $traceAt > $endedAt))) {
            $this->error($path.'.observed_at', 'Trace timestamp must fall within the survey interval.');
        }

        return $traceAt;
    }

    /** @param array<string, mixed> $fact */
    private function validateFact(mixed $type, array $fact, string $path, bool $hasRoute): void
    {
        $requiredText = match ($type) {
            FieldObservationType::DIRECTION_CUE->value => 'cue',
            FieldObservationType::SIGNBOARD->value => 'text',
            FieldObservationType::SERVICE_NOT_OBSERVED->value => 'expected_service',
            FieldObservationType::BOARDING_POINT->value, FieldObservationType::ALIGHTING_POINT->value, FieldObservationType::TERMINAL->value => 'location_name',
            FieldObservationType::STOP_SERVED->value => 'stop_name',
            FieldObservationType::PASS_THROUGH->value => 'location_name',
            FieldObservationType::ROUTE_ENDPOINT->value => 'endpoint_name',
            FieldObservationType::TRANSFER->value => 'other_route_code',
            default => null,
        };
        if ($requiredText !== null && (! is_string($fact[$requiredText] ?? null) || trim($fact[$requiredText]) === '' || mb_strlen($fact[$requiredText]) > 255)) {
            $this->error($path.'.'.$requiredText, "{$requiredText} is required and limited to 255 characters.");
        }
        if (in_array($type, [FieldObservationType::ROUTE_SEEN->value, FieldObservationType::VEHICLE_BOARDED->value], true)
            && ! $hasRoute && (! is_string($fact['route_code'] ?? null) || trim($fact['route_code']) === '' || mb_strlen($fact['route_code']) > 40)) {
            $this->error($path.'.route_code', 'Identify a known route or provide the observed route code.');
        }
        if ($type === FieldObservationType::OTHER->value
            && (! is_string($fact['description'] ?? null) || trim($fact['description']) === '' || mb_strlen($fact['description']) > 1000)) {
            $this->error($path.'.description', 'Other observations require a short structured description.');
        }
        if ($type === FieldObservationType::FARE_PAID->value
            && isset($fact['passenger_class']) && (! is_string($fact['passenger_class']) || mb_strlen($fact['passenger_class']) > 40)) {
            $this->error($path.'.passenger_class', 'Passenger class must be at most 40 characters.');
        }
        $allowedKeys = [
            'route_code', 'other_route_code', 'stop_name', 'location_name', 'endpoint_name', 'landmark', 'side', 'direction',
            'cue', 'text', 'amount', 'currency', 'passenger_class', 'expected_service', 'description', 'from_location', 'to_location',
            'walking_distance_m', 'uncertainty',
        ];
        $this->onlyKeys($fact, $allowedKeys, $path);
        foreach ($fact as $key => $value) {
            if ($key === 'amount' || $key === 'walking_distance_m') {
                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 10000) {
                    $this->error($path.'.'.$key, $key.' must be a finite non-negative number no greater than 10000.');
                }

                continue;
            }
            if ($key === 'currency') {
                continue;
            }
            if (! is_string($value) || mb_strlen($value) > 500) {
                $this->error($path.'.'.$key, 'Structured text fields must be strings of at most 500 characters.');
            }
        }
        if (count($fact) > 20 || strlen((string) json_encode($fact)) > 10000) {
            $this->error($path, 'Structured observation facts exceed the size limit.');
        }
    }

    private function validatePoint(mixed $point, string $path, bool $required): bool
    {
        if ($point === null && ! $required) {
            return false;
        }
        if (! is_array($point) || ($point['type'] ?? null) !== 'Point' || ! is_array($point['coordinates'] ?? null)
            || ! array_is_list($point['coordinates']) || count($point['coordinates']) !== 2) {
            $this->error($path, 'Location must be a GeoJSON Point with [longitude, latitude] coordinates.');

            return false;
        }
        $this->onlyKeys($point, ['type', 'coordinates'], $path);
        [$longitude, $latitude] = $point['coordinates'];
        if (! is_numeric($longitude) || ! is_numeric($latitude) || ! is_finite((float) $longitude) || ! is_finite((float) $latitude)
            || (float) $longitude < -180 || (float) $longitude > 180 || (float) $latitude < -90 || (float) $latitude > 90) {
            $this->error($path.'.coordinates', 'Coordinates must be finite [longitude, latitude] values within WGS84 bounds.');

            return false;
        }

        return true;
    }

    private function timestamp(mixed $value, string $path, bool $required = true): ?DateTimeImmutable
    {
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            $this->error($path, 'Timestamp must be ISO 8601 with an explicit timezone.');

            return null;
        }
        try {
            $timestamp = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \UnexpectedValueException;
            }

            return $timestamp;
        } catch (Throwable) {
            $this->error($path, 'Timestamp is not a valid date and time.');

            return null;
        }
    }

    /** @param array<string, int> $map */
    private function resolve(mixed $stableId, array $map, string $path, string $kind): ?int
    {
        if (! is_string($stableId) || $stableId === '') {
            $this->error($path, "A valid {$kind} stable ID is required.");

            return null;
        }
        if (! array_key_exists($stableId, $map)) {
            $this->error($path, "Unknown {$kind} stable ID: {$stableId}.");

            return null;
        }

        return $map[$stableId];
    }

    /** @param array<string, array{id: int, city_id?: int, route_id?: int}> $map @return array{id: int, city_id?: int, route_id?: int}|null */
    private function resolveRecord(mixed $stableId, array $map, string $path, string $kind): ?array
    {
        if ($stableId === null) {
            return null;
        }
        if (! is_string($stableId) || ! isset($map[$stableId])) {
            $this->error($path, "Unknown {$kind} stable ID.");

            return null;
        }

        return $map[$stableId];
    }

    /** @param list<mixed> $surveys */
    private function loadReferenceMaps(array $surveys): void
    {
        $references = ['cities' => [], 'routes' => [], 'patterns' => [], 'stops' => [], 'places' => [], 'users' => []];
        foreach ($surveys as $survey) {
            if (! is_array($survey)) {
                continue;
            }
            $references['cities'][] = $survey['city_id'] ?? null;
            $references['routes'][] = $survey['route_id'] ?? null;
            $references['patterns'][] = $survey['route_pattern_id'] ?? null;
            $references['users'][] = $survey['observer_user_id'] ?? null;
            foreach (is_array($survey['observations'] ?? null) ? $survey['observations'] : [] as $observation) {
                if (! is_array($observation)) {
                    continue;
                }
                $references['routes'][] = $observation['route_id'] ?? null;
                $references['patterns'][] = $observation['route_pattern_id'] ?? null;
                foreach (['stop_id', 'fare_from_stop_id', 'fare_to_stop_id'] as $field) {
                    $references['stops'][] = $observation[$field] ?? null;
                }
                $references['places'][] = $observation['place_id'] ?? null;
            }
        }
        $userIds = array_values(array_unique(array_filter($references['users'], 'is_int')));
        unset($references['users']);
        $references = array_map(static fn (array $values): array => array_values(array_unique(array_filter($values, 'is_string'))), $references);

        $this->cities = City::query()->where('is_demo', false)->whereIn('stable_id', $references['cities'])->pluck('id', 'stable_id')->map(fn ($id): int => (int) $id)->all();
        $this->routes = Route::query()->where('is_demo', false)->whereIn('stable_id', $references['routes'])->get(['id', 'stable_id', 'city_id'])->mapWithKeys(fn (Route $route): array => [$route->stable_id => ['id' => $route->id, 'city_id' => $route->city_id]])->all();
        $this->patterns = RoutePattern::query()->whereIn('route_patterns.stable_id', $references['patterns'])
            ->where('route_patterns.is_demo', false)
            ->join('routes', 'routes.id', '=', 'route_patterns.route_id')
            ->where('routes.is_demo', false)
            ->get(['route_patterns.id', 'route_patterns.stable_id', 'route_patterns.route_id', 'routes.city_id'])
            ->mapWithKeys(fn (object $pattern): array => [$pattern->stable_id => [
                'id' => (int) $pattern->id,
                'route_id' => (int) $pattern->route_id,
                'city_id' => (int) $pattern->city_id,
            ]])->all();
        $this->stops = Stop::query()->where('is_demo', false)->whereIn('stable_id', $references['stops'])->get(['id', 'stable_id', 'city_id'])->mapWithKeys(fn (Stop $stop): array => [$stop->stable_id => ['id' => $stop->id, 'city_id' => $stop->city_id]])->all();
        $this->places = Place::query()->where('is_demo', false)->whereIn('stable_id', $references['places'])->get(['id', 'stable_id', 'city_id'])->mapWithKeys(fn (Place $place): array => [$place->stable_id => ['id' => $place->id, 'city_id' => $place->city_id]])->all();
        $this->users = User::query()->whereIn('id', $userIds)->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /** @param array<string, mixed> $record @param list<string> $allowed */
    private function onlyKeys(array $record, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($record), $allowed) as $key) {
            $this->error($path.'.'.$key, 'Unexpected field is not allowed.');
        }
    }

    /** @param array<string, true> $ids */
    private function stableId(mixed $value, string $path, array &$ids): void
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,149}$/D', $value) !== 1) {
            $this->error($path, 'Stable IDs must be 1–150 safe ASCII letters, digits, dots, underscores, colons, or hyphens.');

            return;
        }
        if (isset($ids[$value])) {
            $this->error($path, "Duplicate stable ID: {$value}.");

            return;
        }
        $ids[$value] = true;
    }

    private function error(string $path, string $message): void
    {
        $this->errors[] = ['path' => $path, 'message' => $message];
    }
}
