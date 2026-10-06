<?php

namespace App\Services\FieldSurveys;

final class SurveyImportValidationResult
{
    /** @param array<string, mixed>|null $data @param list<array{path: string, message: string}> $errors */
    public function __construct(
        public readonly ?array $data,
        private readonly array $errors,
        public readonly int $recordsExamined,
    ) {}

    /** @return list<array{path: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->data !== null;
    }

    public function rejectedCount(): int
    {
        return count(array_unique(array_column($this->errors, 'path')));
    }

    public function validCount(): int
    {
        return max(0, $this->recordsExamined - $this->rejectedCount());
    }
}
