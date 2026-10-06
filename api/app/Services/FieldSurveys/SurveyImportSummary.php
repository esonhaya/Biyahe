<?php

namespace App\Services\FieldSurveys;

final class SurveyImportSummary
{
    /** @var array<string, array{examined: int, imported: int, updated: int, skipped: int, rejected: int}> */
    private array $counts = [];

    public function record(string $type, string $action): void
    {
        $this->counts[$type] ??= ['examined' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => 0];
        $this->counts[$type]['examined']++;
        $this->counts[$type][$action]++;
    }

    /** @return array<string, array{examined: int, imported: int, updated: int, skipped: int, rejected: int}> */
    public function counts(): array
    {
        return $this->counts;
    }

    public function total(string $field): int
    {
        return array_sum(array_column($this->counts, $field));
    }
}
