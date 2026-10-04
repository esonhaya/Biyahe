<?php

namespace App\Services\Data;

final class DatasetValidationResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $path,
        public readonly ?array $data,
    ) {}

    /** @var list<array{path: string, message: string}> */
    private array $errors = [];

    /** @var list<array{path: string, message: string}> */
    private array $warnings = [];

    public function addError(string $path, string $message): void
    {
        $this->errors[] = ['path' => $path, 'message' => $message];
    }

    public function addWarning(string $path, string $message): void
    {
        $this->warnings[] = ['path' => $path, 'message' => $message];
    }

    /** @return list<array{path: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<array{path: string, message: string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function recordCount(): int
    {
        if ($this->data === null) {
            return 0;
        }

        return collect(['sources', 'cities', 'places', 'aliases', 'stops', 'operators', 'routes', 'patterns', 'fares'])
            ->sum(fn (string $collection): int => is_array($this->data[$collection] ?? null) ? count($this->data[$collection]) : 0);
    }

    public function rejectedCount(): int
    {
        $paths = [];
        foreach ($this->errors as $error) {
            if (preg_match('/^([a-z_]+\\.\\d+)/', $error['path'], $matches) === 1) {
                $paths[$matches[1]] = true;
            } else {
                $paths[$error['path']] = true;
            }
        }

        return count($paths);
    }

    public function validCount(): int
    {
        return max(0, $this->recordCount() - $this->rejectedCount());
    }

    public function duplicateCount(): int
    {
        return collect($this->errors)
            ->filter(fn (array $error): bool => str_contains(strtolower($error['message']), 'duplicate'))
            ->count();
    }
}
