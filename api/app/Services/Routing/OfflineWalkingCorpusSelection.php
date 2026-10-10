<?php

namespace App\Services\Routing;

use InvalidArgumentException;

final class OfflineWalkingCorpusSelection
{
    public const CASE_COUNT = 10;

    /** @return list<int>|null */
    public static function parse(?string $selection): ?array
    {
        if ($selection === null) {
            return null;
        }

        if ($selection === '') {
            throw new InvalidArgumentException('Case selection must contain at least one zero-based index.');
        }

        $parts = explode(',', $selection);
        foreach ($parts as $part) {
            if (! preg_match('/^(0|[1-9][0-9]*)$/', $part)) {
                throw new InvalidArgumentException('Case selection must be comma-separated zero-based indices from 0 through 9.');
            }
        }

        return self::normalize(array_map(static fn (string $part): int => (int) $part, $parts));
    }

    /** @param list<int>|null $indices @return list<int>|null */
    public static function normalize(?array $indices): ?array
    {
        if ($indices === null) {
            return null;
        }
        if ($indices === []) {
            throw new InvalidArgumentException('At least one corpus case index is required.');
        }

        foreach ($indices as $index) {
            if (! is_int($index) || $index < 0 || $index >= self::CASE_COUNT) {
                throw new InvalidArgumentException('Case indices must be integers from 0 through 9.');
            }
        }

        $normalized = array_values(array_unique($indices));
        if (count($normalized) !== count($indices)) {
            throw new InvalidArgumentException('Case indices must not be repeated.');
        }
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /** @param list<int>|null $indices */
    public static function isComplete(?array $indices): bool
    {
        return $indices === null || $indices === range(0, self::CASE_COUNT - 1);
    }
}
