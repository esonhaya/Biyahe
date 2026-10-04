<?php

namespace App\Services\Data;

final class ImportSummary
{
    /** @var array<string, array{examined: int, imported: int, updated: int, skipped: int}> */
    private array $records = [];

    public function record(string $type, string $action): void
    {
        $this->records[$type] ??= ['examined' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0];
        $this->records[$type]['examined']++;
        $this->records[$type][$action]++;
    }

    /** @return array<string, array{examined: int, imported: int, updated: int, skipped: int}> */
    public function records(): array
    {
        return $this->records;
    }

    public function total(string $field): int
    {
        return array_sum(array_column($this->records(), $field));
    }
}
