<?php

namespace App\Support;

final class SearchText
{
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';

        return $value;
    }

    /** @return list<string> */
    public static function tokens(string $value): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($value))) ?: [];

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    /** @param iterable<string> $aliases */
    public static function score(string $query, string $name, iterable $aliases): int
    {
        $normalizedQuery = self::normalize($query);
        $normalizedName = self::normalize($name);
        $normalizedAliases = [];
        foreach ($aliases as $alias) {
            $normalizedAliases[] = self::normalize($alias);
        }

        if ($normalizedQuery === $normalizedName) {
            return 0;
        }
        if (in_array($normalizedQuery, $normalizedAliases, true)) {
            return 1;
        }
        if (str_starts_with($normalizedName, $normalizedQuery)) {
            return 2;
        }
        foreach ($normalizedAliases as $alias) {
            if (str_starts_with($alias, $normalizedQuery)) {
                return 3;
            }
        }
        if (str_contains($normalizedName, $normalizedQuery)) {
            return 4;
        }
        foreach ($normalizedAliases as $alias) {
            if (str_contains($alias, $normalizedQuery)) {
                return 5;
            }
        }

        return 6;
    }
}
