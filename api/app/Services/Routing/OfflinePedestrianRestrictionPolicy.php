<?php

namespace App\Services\Routing;

use JsonException;
use PDO;
use RuntimeException;

/** Conservative mode and member-shape classification for OSM turn restrictions. */
final class OfflinePedestrianRestrictionPolicy
{
    public const VERSION = 'osm-pedestrian-turn-restrictions-v1';

    private const PEDESTRIAN_KEYS = [
        'restriction:foot',
        'restriction:pedestrian',
        'restriction:foot:conditional',
        'restriction:pedestrian:conditional',
    ];

    private const VEHICLE_MODES = [
        'agricultural', 'bicycle', 'bus', 'emergency', 'forestry', 'goods', 'hgv',
        'hov', 'motor_vehicle', 'motorcar', 'motorcycle', 'moped', 'psv', 'taxi', 'vehicle',
    ];

    /**
     * Classify the mode and relation-member shape independently. A malformed
     * generic vehicle restriction is still not a pedestrian restriction.
     *
     * @param array<string, mixed> $tags
     * @param list<array{member_type: string, role: string, member_ref?: int}> $members
     * @return array<string, mixed>
     */
    public static function classify(array $tags, array $members): array
    {
        $normalized = [];
        foreach ($tags as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $normalized[strtolower(trim($key))] = strtolower(trim((string) $value));
            }
        }

        $except = preg_split('/[;,]/', $normalized['except'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $except = array_values(array_unique(array_map('trim', $except)));
        $pedestrianExcepted = in_array('foot', $except, true) || in_array('pedestrian', $except, true);
        $recognizedExceptModes = [...self::VEHICLE_MODES, 'foot', 'pedestrian'];
        $unknownExceptModes = array_values(array_diff($except, $recognizedExceptModes));
        $pedestrianKeysPresent = [];
        foreach (self::PEDESTRIAN_KEYS as $key) {
            if (array_key_exists($key, $normalized)) {
                $pedestrianKeysPresent[] = $key;
            }
        }
        $explicitType = in_array($normalized['type'] ?? '', ['restriction:foot', 'restriction:pedestrian'], true);
        $vehicleSpecific = [];
        $unknownSpecific = [];
        foreach (array_keys($normalized) as $key) {
            if (! str_starts_with($key, 'restriction:') || str_ends_with($key, ':conditional')) {
                continue;
            }
            $mode = substr($key, strlen('restriction:'));
            if (in_array($mode, ['foot', 'pedestrian'], true)) {
                continue;
            }
            if (in_array($mode, self::VEHICLE_MODES, true)) {
                $vehicleSpecific[] = $key;
            } else {
                $unknownSpecific[] = $key;
            }
        }

        if ($unknownExceptModes !== []) {
            $mode = 'ambiguous';
            $reason = 'unknown_exception_mode';
        } elseif (($pedestrianKeysPresent !== [] || $explicitType) && $pedestrianExcepted) {
            $mode = 'ambiguous';
            $reason = 'conflicting_pedestrian_restriction_and_exception';
        } elseif ($pedestrianKeysPresent !== [] || $explicitType) {
            $mode = 'pedestrian_applicable';
            $reason = 'explicit_pedestrian_restriction_tag';
        } elseif ($unknownSpecific !== []) {
            $mode = 'ambiguous';
            $reason = 'unknown_mode_specific_restriction_key';
        } elseif ($pedestrianExcepted && ($normalized['type'] ?? '') === 'restriction'
            && (isset($normalized['restriction']) || isset($normalized['restriction:conditional']) || $vehicleSpecific !== [])) {
            $mode = 'pedestrian_exempt';
            $reason = 'except_explicitly_includes_foot';
        } elseif (($normalized['type'] ?? '') === 'restriction'
            && (isset($normalized['restriction']) || isset($normalized['restriction:conditional']) || $vehicleSpecific !== [])) {
            $mode = 'vehicle_only';
            $reason = $vehicleSpecific !== [] ? 'restriction_is_limited_to_non_pedestrian_mode' : 'generic_turn_restriction_does_not_apply_to_pedestrians';
        } else {
            $mode = 'ambiguous';
            $reason = 'restriction_mode_not_proven';
        }

        $conditional = false;
        foreach (array_keys($normalized) as $key) {
            if (str_ends_with($key, ':conditional')) {
                $conditional = true;
                break;
            }
        }
        $pedestrianConditional = isset($normalized['restriction:foot:conditional'])
            || isset($normalized['restriction:pedestrian:conditional']);
        $shape = self::memberShape($members);
        $blocksRouting = in_array($mode, ['pedestrian_applicable', 'ambiguous'], true);

        return [
            'mode_applicability' => $mode,
            'reason_code' => $reason,
            'conditional' => $conditional,
            'pedestrian_conditional' => $pedestrianConditional,
            'member_form' => $shape['form'],
            'member_shape_valid' => $shape['valid'],
            'member_shape_reason' => $shape['reason'],
            'unsupported_for_routing' => $blocksRouting,
            'enforced_by_router' => false,
            'normalized_tags' => $normalized,
            'members' => $members,
        ];
    }

    /**
     * Read and classify every restriction dependency preserved in the SQLite
     * artifact. The bounded Cebu inventory is small; records remain sorted.
     *
     * @return array<string, mixed>
     */
    public function audit(PDO $sqlite): array
    {
        $relations = $sqlite->query('SELECT relation_id, tags, member_count, members_sha256 FROM source_restrictions ORDER BY relation_id');
        $memberQuery = $sqlite->prepare('SELECT sequence_index, member_type, member_ref, role FROM source_restriction_members WHERE relation_id=? ORDER BY sequence_index');
        $memberExists = [
            'node' => $sqlite->prepare('SELECT 1 FROM source_nodes WHERE id=?'),
            'way' => $sqlite->prepare('SELECT 1 FROM seen_way_ids WHERE id=?'),
            'relation' => $sqlite->prepare('SELECT 1 FROM seen_relation_ids WHERE id=?'),
        ];
        $records = [];
        $counts = [
            'total' => 0,
            'vehicle_only' => 0,
            'pedestrian_applicable' => 0,
            'pedestrian_exempt' => 0,
            'ambiguous' => 0,
            'conditional' => 0,
            'pedestrian_conditional' => 0,
            'via_node' => 0,
            'via_way' => 0,
            'mixed_via' => 0,
            'nested_complex' => 0,
            'malformed' => 0,
            'relations_with_missing_member_dependencies' => 0,
            'missing_member_dependencies' => 0,
            'unsupported_applicable' => 0,
        ];
        $fingerprint = hash_init('sha256');

        while ($relation = $relations->fetch(PDO::FETCH_ASSOC)) {
            try {
                $tags = json_decode((string) $relation['tags'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Offline restriction relation contains invalid JSON tags.', 0, $exception);
            }
            if (! is_array($tags)) {
                throw new RuntimeException('Offline restriction relation tags must be a JSON object.');
            }
            $memberQuery->execute([(int) $relation['relation_id']]);
            $members = [];
            while ($member = $memberQuery->fetch(PDO::FETCH_ASSOC)) {
                $members[] = [
                    'sequence_index' => (int) $member['sequence_index'],
                    'member_type' => (string) $member['member_type'],
                    'member_ref' => (int) $member['member_ref'],
                    'role' => (string) $member['role'],
                ];
            }
            $memberQuery->closeCursor();
            $missingDependencies = [];
            foreach ($members as $member) {
                $existsQuery = $memberExists[$member['member_type']] ?? null;
                if ($existsQuery === null) {
                    $missingDependencies[] = $member;

                    continue;
                }
                $existsQuery->execute([$member['member_ref']]);
                $exists = $existsQuery->fetchColumn() !== false;
                $existsQuery->closeCursor();
                if (! $exists) {
                    $missingDependencies[] = $member;
                }
            }
            $classification = self::classify($tags, array_map(static fn (array $member): array => [
                'member_type' => $member['member_type'],
                'role' => $member['role'],
                'member_ref' => $member['member_ref'],
            ], $members));
            if (count($members) !== (int) $relation['member_count']) {
                throw new RuntimeException('Offline restriction member count changed during audit.');
            }

            $mode = (string) $classification['mode_applicability'];
            $form = (string) $classification['member_form'];
            $counts['total']++;
            $counts[$mode]++;
            if ($classification['conditional']) {
                $counts['conditional']++;
            }
            if ($classification['pedestrian_conditional']) {
                $counts['pedestrian_conditional']++;
            }
            if (array_key_exists($form, $counts) && in_array($form, ['via_node', 'via_way', 'mixed_via', 'nested_complex', 'malformed'], true)) {
                $counts[$form]++;
            }
            if ($classification['unsupported_for_routing']) {
                $counts['unsupported_applicable']++;
            }
            if ($missingDependencies !== []) {
                $counts['relations_with_missing_member_dependencies']++;
                $counts['missing_member_dependencies'] += count($missingDependencies);
            }

            $record = [
                'relation_id' => (int) $relation['relation_id'],
                'tags' => $tags,
                'member_count' => (int) $relation['member_count'],
                'members_sha256' => (string) $relation['members_sha256'],
                'classification' => $classification,
                'member_dependencies_complete' => $missingDependencies === [],
                'missing_member_dependencies' => $missingDependencies,
            ];
            $records[] = $record;
            hash_update($fingerprint, json_encode([
                'relation_id' => $record['relation_id'],
                'tags' => $record['tags'],
                'member_count' => $record['member_count'],
                'members_sha256' => $record['members_sha256'],
                'members' => $members,
                'mode_applicability' => $classification['mode_applicability'],
                'reason_code' => $classification['reason_code'],
                'member_form' => $classification['member_form'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        }

        return [
            'policy_version' => self::VERSION,
            'counts' => $counts,
            'stored_inventory_fingerprint' => hash_final($fingerprint),
            'supported_relation_forms' => [],
            'enforced_relation_forms' => [],
            'relations' => $records,
        ];
    }

    /** @param list<array{member_type: string, role: string}> $members @return array{form: string, valid: bool, reason: string} */
    private static function memberShape(array $members): array
    {
        $from = $to = $viaNodes = $viaWays = 0;
        $nested = false;
        $odd = false;
        foreach ($members as $member) {
            $type = $member['member_type'] ?? '';
            $role = $member['role'] ?? '';
            if ($type === 'relation') {
                $nested = true;

                continue;
            }
            if (! in_array($type, ['node', 'way'], true) || ! in_array($role, ['from', 'to', 'via'], true)) {
                $odd = true;

                continue;
            }
            if ($role === 'from' && $type === 'way') {
                $from++;
            } elseif ($role === 'to' && $type === 'way') {
                $to++;
            } elseif ($role === 'via' && $type === 'node') {
                $viaNodes++;
            } elseif ($role === 'via' && $type === 'way') {
                $viaWays++;
            } else {
                $odd = true;
            }
        }

        if ($nested) {
            return ['form' => 'nested_complex', 'valid' => false, 'reason' => 'nested_relation_member'];
        }
        if ($odd || $from !== 1 || $to !== 1 || ($viaNodes + $viaWays) === 0) {
            return ['form' => 'malformed', 'valid' => false, 'reason' => 'member_roles_or_counts_incomplete'];
        }
        if ($viaNodes > 0 && $viaWays > 0) {
            return ['form' => 'mixed_via', 'valid' => false, 'reason' => 'mixed_node_and_way_via_members'];
        }
        if ($viaNodes > 1) {
            return ['form' => 'nested_complex', 'valid' => false, 'reason' => 'multiple_via_nodes'];
        }

        return $viaNodes === 1
            ? ['form' => 'via_node', 'valid' => true, 'reason' => 'standard_from_via_node_to_shape']
            : ['form' => 'via_way', 'valid' => true, 'reason' => 'standard_from_via_way_chain_to_shape'];
    }
}
