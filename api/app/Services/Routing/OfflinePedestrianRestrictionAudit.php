<?php

namespace App\Services\Routing;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

/** Builds and verifies an immutable audit sidecar bound to one graph and PBF. */
final class OfflinePedestrianRestrictionAudit
{
    public const FORMAT = 'biyahe-pedestrian-restriction-audit-v1';

    public const INVENTORY_VERSION = 'osm-turn-restriction-inventory-v1';

    public function __construct(private readonly OfflinePedestrianRestrictionPolicy $policy = new OfflinePedestrianRestrictionPolicy) {}

    /** @param array<string, mixed> $sourceScan @return array<string, mixed> */
    public function build(PDO $sqlite, array $sourceScan): array
    {
        $metadata = $this->metadata($sqlite);
        $stored = $this->policy->audit($sqlite);
        $this->assertSourceScanShape($sourceScan, $metadata, $stored);
        $omitted = $sourceScan['omitted_relations'];
        $sourceCounts = [
            'total_turn_restrictions' => (int) $sourceScan['matching_relation_count'],
            'vehicle_only' => (int) $stored['counts']['vehicle_only'] + count($omitted),
            'pedestrian_applicable' => (int) $stored['counts']['pedestrian_applicable'],
            'pedestrian_exempt' => (int) $stored['counts']['pedestrian_exempt'],
            'ambiguous' => (int) $stored['counts']['ambiguous'],
            'conditional' => (int) $sourceScan['tag_key_counts']['restriction:conditional'],
            'via_node' => (int) $stored['counts']['via_node'],
            'via_way' => (int) $stored['counts']['via_way'],
            'malformed_or_complex_members' => (int) $stored['counts']['malformed']
                + (int) $stored['counts']['nested_complex'] + (int) $stored['counts']['mixed_via'],
            'unsupported_applicable' => (int) $stored['counts']['unsupported_applicable'],
        ];

        return [
            'format' => self::FORMAT,
            'policy_version' => OfflinePedestrianRestrictionPolicy::VERSION,
            'inventory_version' => self::INVENTORY_VERSION,
            'source' => [
                'sha256' => (string) $metadata['source_sha256'],
                'snapshot' => (string) $metadata['source_timestamp'],
                'url' => (string) $metadata['source_url'],
                'osmium_version' => (string) $metadata['source_osmium_version'],
            ],
            'graph' => [
                'format' => (string) $metadata['format'],
                'schema_version' => (int) $metadata['schema_version'],
                'content_fingerprint' => (string) $metadata['graph_content_sha256'],
                'nodes' => (int) $metadata['graph_nodes'],
                'edges' => (int) $metadata['graph_edges'],
                'stored_restriction_relations' => (int) $stored['counts']['total'],
            ],
            'source_scan' => $sourceScan,
            'stored_inventory' => $stored,
            'source_classification_counts' => $sourceCounts,
            'routing_capabilities' => [
                'source_restriction_inventory_complete' => true,
                'pedestrian_turn_restrictions_enforced' => false,
                'via_node_enforced' => false,
                'via_way_enforced' => false,
                'conditional_turn_restrictions_evaluated' => false,
                'routing_ready' => $sourceCounts['pedestrian_applicable'] === 0
                    && $sourceCounts['ambiguous'] === 0
                    && $sourceCounts['unsupported_applicable'] === 0,
                'reason' => 'The complete source restriction inventory contains no pedestrian-applicable or mode-ambiguous turn restriction. Generic vehicle restrictions are not applied to walking.',
            ],
        ];
    }

    /** @param array<string, mixed> $metadata @param array<string, mixed> $stored @return array{complete: bool, status: string, reason: string, source_relation_count: int, omitted_relation_count: int} */
    public function verify(PDO $sqlite, array $metadata, array $stored, ?string $auditPath): array
    {
        if (($metadata['restriction_inventory_complete'] ?? false) === true
            && ($metadata['restriction_inventory_policy_version'] ?? null) === self::INVENTORY_VERSION) {
            return [
                'complete' => true,
                'status' => 'PASS',
                'reason' => 'The graph records a complete source restriction inventory under the current checkpointed inventory policy.',
                'source_relation_count' => (int) $stored['counts']['total'],
                'omitted_relation_count' => 0,
            ];
        }

        if ($auditPath === null || ! is_file($auditPath) || ! is_readable($auditPath)) {
            return [
                'complete' => false,
                'status' => 'BLOCKED',
                'reason' => 'The legacy graph does not prove complete restriction-tag capture and has no matching source-scan audit sidecar.',
                'source_relation_count' => 0,
                'omitted_relation_count' => 0,
            ];
        }

        try {
            $report = json_decode((string) file_get_contents($auditPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->invalid('restriction_audit_json_invalid');
        }
        if (! is_array($report)
            || ($report['format'] ?? null) !== self::FORMAT
            || ($report['policy_version'] ?? null) !== OfflinePedestrianRestrictionPolicy::VERSION
            || ($report['inventory_version'] ?? null) !== self::INVENTORY_VERSION
            || ! is_array($report['source'] ?? null)
            || ! is_array($report['graph'] ?? null)
            || ! is_array($report['source_scan'] ?? null)
            || ! is_array($report['stored_inventory'] ?? null)) {
            return $this->invalid('restriction_audit_schema_invalid');
        }

        $sourceHash = strtolower((string) ($metadata['source_sha256'] ?? ''));
        $graphFingerprint = strtolower((string) ($metadata['graph_content_sha256'] ?? ''));
        $sourceMatches = hash_equals($sourceHash, strtolower((string) ($report['source']['sha256'] ?? '')))
            && hash_equals($sourceHash, strtolower((string) ($report['source_scan']['source_sha256'] ?? '')))
            && $this->sameTimestamp((string) ($metadata['source_timestamp'] ?? ''), (string) ($report['source']['snapshot'] ?? ''))
            && $this->sameTimestamp((string) ($metadata['source_timestamp'] ?? ''), (string) ($report['source_scan']['source_snapshot'] ?? ''));
        $graphMatches = hash_equals($graphFingerprint, strtolower((string) ($report['graph']['content_fingerprint'] ?? '')))
            && (int) ($report['graph']['schema_version'] ?? -1) === (int) ($metadata['schema_version'] ?? -2)
            && (int) ($report['graph']['stored_restriction_relations'] ?? -1) === (int) $stored['counts']['total'];
        $storedMatches = hash_equals(
            strtolower((string) ($stored['stored_inventory_fingerprint'] ?? '')),
            strtolower((string) ($report['stored_inventory']['stored_inventory_fingerprint'] ?? '')),
        ) && (int) ($report['stored_inventory']['counts']['total'] ?? -1) === (int) $stored['counts']['total'];

        if (! $sourceMatches || ! $graphMatches || ! $storedMatches) {
            return $this->invalid('restriction_audit_source_graph_or_inventory_mismatch');
        }

        $sourceScan = $report['source_scan'];
        $omitted = $sourceScan['omitted_relations'] ?? null;
        $typeValues = $sourceScan['type_values'] ?? null;
        $pedestrianTagCounts = $sourceScan['pedestrian_specific_tag_counts'] ?? null;
        $relationCount = (int) ($sourceScan['matching_relation_count'] ?? -1);
        $storedCount = (int) $stored['counts']['total'];
        $typeValuesComplete = is_array($typeValues) && ($typeValues === ['restriction' => $relationCount]
            || ($relationCount === 0 && $typeValues === []));
        $pedestrianTagsAbsent = is_array($pedestrianTagCounts);
        foreach (['restriction:foot', 'restriction:pedestrian', 'restriction:foot:conditional', 'restriction:pedestrian:conditional'] as $key) {
            $pedestrianTagsAbsent = $pedestrianTagsAbsent && (int) ($pedestrianTagCounts[$key] ?? -1) === 0;
        }
        if (($sourceScan['complete'] ?? false) !== true
            || (int) ($sourceScan['producer_exit_code'] ?? -1) !== 0
            || (int) ($sourceScan['stored_relation_count'] ?? -1) !== $storedCount
            || ! is_array($omitted) || (int) ($sourceScan['omitted_relation_count'] ?? -1) !== count($omitted)
            || $relationCount !== $storedCount + count($omitted)
            || ! $typeValuesComplete
            || ! $pedestrianTagsAbsent) {
            return $this->invalid('restriction_source_inventory_incomplete_or_ambiguous');
        }

        $seen = $sqlite->prepare('SELECT 1 FROM seen_relation_ids WHERE id=?');
        $storedIds = $sqlite->prepare('SELECT 1 FROM source_restrictions WHERE relation_id=?');
        foreach ($omitted as $row) {
            $id = is_array($row) ? (int) ($row['relation_id'] ?? 0) : 0;
            if ($id < 1) {
                return $this->invalid('restriction_source_omission_id_invalid');
            }
            $seen->execute([$id]);
            $isSeen = $seen->fetchColumn() !== false;
            $seen->closeCursor();
            $storedIds->execute([$id]);
            $isStored = $storedIds->fetchColumn() !== false;
            $storedIds->closeCursor();
            if (! $isSeen || $isStored) {
                return $this->invalid('restriction_source_omission_not_bound_to_checkpoint');
            }
            $tags = is_array($row['tags'] ?? null) ? $row['tags'] : [];
            $classification = OfflinePedestrianRestrictionPolicy::classify($tags, []);
            if ($classification['mode_applicability'] !== 'vehicle_only') {
                return $this->invalid('restriction_source_omission_not_proven_vehicle_only');
            }
        }

        return [
            'complete' => true,
            'status' => 'PASS',
            'reason' => 'The source PBF restriction scan, stored relation/member fingerprint, and all source relations omitted by v3 capture are bound to this graph and checkpoint.',
            'source_relation_count' => $relationCount,
            'omitted_relation_count' => count($omitted),
        ];
    }

    /** @param array<string, mixed> $sourceScan @param array<string, mixed> $metadata @param array<string, mixed> $stored */
    private function assertSourceScanShape(array $sourceScan, array $metadata, array $stored): void
    {
        if (($sourceScan['format'] ?? null) !== 'biyahe-osm-restriction-source-scan-v1'
            || ($sourceScan['complete'] ?? false) !== true
            || (int) ($sourceScan['producer_exit_code'] ?? -1) !== 0
            || ! hash_equals(strtolower((string) ($metadata['source_sha256'] ?? '')), strtolower((string) ($sourceScan['source_sha256'] ?? '')))
            || ! $this->sameTimestamp((string) ($metadata['source_timestamp'] ?? ''), (string) ($sourceScan['source_snapshot'] ?? ''))
            || (int) ($sourceScan['stored_relation_count'] ?? -1) !== (int) $stored['counts']['total']) {
            throw new RuntimeException('Source restriction-scan evidence does not match the graph source identity or stored relation count.');
        }
    }

    /** @return array{complete: bool, status: string, reason: string, source_relation_count: int, omitted_relation_count: int} */
    private function invalid(string $reason): array
    {
        return ['complete' => false, 'status' => 'FAIL', 'reason' => $reason, 'source_relation_count' => 0, 'omitted_relation_count' => 0];
    }

    private function sameTimestamp(string $left, string $right): bool
    {
        try {
            return (new DateTimeImmutable($left))->format(DATE_ATOM) === (new DateTimeImmutable($right))->format(DATE_ATOM);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function metadata(PDO $sqlite): array
    {
        $metadata = [];
        foreach ($sqlite->query('SELECT key, value FROM metadata') as $row) {
            $metadata[(string) $row['key']] = json_decode((string) $row['value'], true, 32, JSON_THROW_ON_ERROR);
        }

        return $metadata;
    }
}
