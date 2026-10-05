<?php

namespace App\Support\Diagnostics;

use InvalidArgumentException;

final class DoctorCheckResult
{
    /** @var list<string> */
    private const STATUSES = ['PASS', 'WARN', 'FAIL', 'BLOCKED'];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $status,
        public readonly string $message,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported Doctor status: '.$status);
        }
    }
}
