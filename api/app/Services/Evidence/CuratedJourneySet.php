<?php

namespace App\Services\Evidence;

use App\Enums\EvidenceClaimType;
use RuntimeException;

final class CuratedJourneySet
{
    /** @return list<array<string, mixed>> */
    public function scenarios(): array
    {
        $path = (string) config('biyahe.evidence.curated_journeys_path');
        if (! is_file($path)) {
            return [];
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Curated journey set is invalid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($document) || ($document['format'] ?? null) !== 'biyahe-curated-journey-set' || ($document['version'] ?? null) !== 1) {
            throw new RuntimeException('Curated journey set has an unsupported format or version.');
        }

        $scenarios = $document['scenarios'] ?? null;
        if (! is_array($scenarios)) {
            throw new RuntimeException('Curated journey set scenarios must be an array.');
        }
        if ($scenarios === []) {
            throw new RuntimeException('Curated journey set must contain at least one scenario.');
        }

        $seen = [];
        $valid = [];
        foreach ($scenarios as $index => $scenario) {
            if (! is_array($scenario)) {
                throw new RuntimeException("Curated scenario {$index} must be an object.");
            }
            foreach (['stable_id', 'origin_place_slug', 'destination_place_slug'] as $field) {
                if (! is_string($scenario[$field] ?? null) || trim($scenario[$field]) === '') {
                    throw new RuntimeException("Curated scenario {$index}.{$field} must be a non-empty string.");
                }
            }
            if ($scenario['origin_place_slug'] === $scenario['destination_place_slug']) {
                throw new RuntimeException("Curated scenario {$scenario['stable_id']} must use different origin and destination places.");
            }
            if (isset($seen[$scenario['stable_id']])) {
                throw new RuntimeException('Duplicate curated scenario stable_id '.$scenario['stable_id'].'.');
            }
            $seen[$scenario['stable_id']] = true;
            if (! is_array($scenario['expected_route_codes'] ?? null) || ! is_array($scenario['required_evidence'] ?? null)) {
                throw new RuntimeException('Curated scenario '.$scenario['stable_id'].' must declare route codes and required evidence.');
            }
            foreach (['expected_route_codes', 'required_evidence'] as $field) {
                foreach ($scenario[$field] as $value) {
                    if (! is_string($value) || trim($value) === '') {
                        throw new RuntimeException('Curated scenario '.$scenario['stable_id'].'.'.$field.' must contain non-empty strings.');
                    }
                }
            }
            foreach ($scenario['required_evidence'] as $claim) {
                if (! in_array($claim, [...EvidenceClaimType::values(), 'BOARDING'], true)) {
                    throw new RuntimeException('Curated scenario '.$scenario['stable_id'].' references unsupported evidence claim '.$claim.'.');
                }
            }
            $valid[] = [
                'stable_id' => $scenario['stable_id'],
                'origin_place_slug' => $scenario['origin_place_slug'],
                'destination_place_slug' => $scenario['destination_place_slug'],
                'expected_route_codes' => array_values($scenario['expected_route_codes']),
                'required_evidence' => array_values($scenario['required_evidence']),
            ];
        }

        usort($valid, static fn (array $left, array $right): int => strcmp($left['stable_id'], $right['stable_id']));

        return $valid;
    }
}
