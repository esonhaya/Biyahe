<?php

namespace App\Support\Diagnostics;

final class DoctorReport
{
    /** @var list<DoctorCheckResult> */
    private array $checks = [];

    public function add(DoctorCheckResult $check): void
    {
        $this->checks[] = $check;
    }

    /** @return list<DoctorCheckResult> */
    public function checks(): array
    {
        return $this->checks;
    }

    public function count(string $status): int
    {
        return count(array_filter(
            $this->checks,
            fn (DoctorCheckResult $check): bool => $check->status === $status,
        ));
    }

    public function hasCriticalFailure(): bool
    {
        return $this->count('FAIL') > 0;
    }

    public function isBlocked(): bool
    {
        return $this->count('BLOCKED') > 0;
    }
}
