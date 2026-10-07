<?php

namespace App\Services\Evidence;

use Illuminate\Support\Facades\DB;

final class NetworkIntelligenceAudit
{
    /** @param array<string, array<string, mixed>> $auditResults */
    public function analyze(array $auditResults = [], int $limit = 30): array
    {
        return [
            'evidence_priorities' => $this->evidencePriorities($auditResults, $limit),
            'pedestrian_gaps' => $this->pedestrianGaps(),
            'data_quality' => $this->dataQuality(),
        ];
    }

    /** @param array<string, array<string, mixed>> $auditResults @return array<string, mixed> */
    private function evidencePriorities(array $auditResults, int $limit): array
    {
        $impact = [];
        foreach ($auditResults as $result) {
            if (($result['status'] ?? null) !== 'COMPLETE') {
                continue;
            }
            $pair = ($result['origin'] ?? '?').'→'.($result['destination'] ?? '?');
            foreach (($result['journey_refs'] ?? []) as $journey) {
                foreach (($journey['rides'] ?? []) as $ride) {
                    $patternId = (int) ($ride['pattern_id'] ?? 0);
                    $routeId = (int) ($ride['route_id'] ?? 0);
                    $key = 'PATTERN_SERVICE:'.$patternId;
                    $impact[$key] ??= [
                        'claim_type' => 'SERVICE',
                        'record_type' => 'route_pattern',
                        'record_id' => $patternId,
                        'route_id' => $routeId,
                        'affected_pairs' => [],
                        'affected_journeys' => 0,
                    ];
                    $impact[$key]['affected_pairs'][$pair] = true;
                    $impact[$key]['affected_journeys']++;

                    foreach ([
                        ['stop_field' => 'boarding_stop_id', 'sequence_field' => 'boarding_sequence', 'claim_type' => 'BOARDING'],
                        ['stop_field' => 'alighting_stop_id', 'sequence_field' => 'alighting_sequence', 'claim_type' => 'ALIGHTING'],
                    ] as $claim) {
                        $stopId = (int) ($ride[$claim['stop_field']] ?? 0);
                        if ($stopId === 0) {
                            continue;
                        }
                        $claimType = $claim['claim_type'];
                        $claimKey = $patternId.':'.$stopId.':'.$claimType;
                        $impact[$claimKey] ??= [
                            'claim_type' => $claimType,
                            'record_type' => 'route_pattern_stop',
                            'pattern_id' => $patternId,
                            'stop_id' => $stopId,
                            'affected_pairs' => [],
                            'affected_journeys' => 0,
                        ];
                        $impact[$claimKey]['affected_pairs'][$pair] = true;
                        $impact[$claimKey]['affected_journeys']++;

                        $sequence = $ride[$claim['sequence_field']] ?? null;
                        if ($sequence !== null) {
                            $sequenceKey = $patternId.':'.$stopId.':SEQUENCE:'.(int) $sequence;
                            $impact[$sequenceKey] ??= [
                                'claim_type' => 'SEQUENCE',
                                'record_type' => 'route_pattern_stop',
                                'record_id' => $patternId,
                                'pattern_id' => $patternId,
                                'stop_id' => $stopId,
                                'sequence' => (int) $sequence,
                                'affected_pairs' => [],
                                'affected_journeys' => 0,
                            ];
                            $impact[$sequenceKey]['affected_pairs'][$pair] = true;
                            $impact[$sequenceKey]['affected_journeys']++;
                        }
                    }
                }
            }
        }

        $patternIds = collect($impact)->pluck('record_id')->filter()->unique()->values()->all();
        $stopIds = collect($impact)->pluck('stop_id')->filter()->unique()->values()->all();
        $patterns = $patternIds === [] ? collect() : DB::table('route_patterns as rp')
            ->join('routes as r', 'r.id', '=', 'rp.route_id')
            ->whereIn('rp.id', $patternIds)
            ->get(['rp.id', 'rp.stable_id', 'rp.direction', 'r.code as route_code'])
            ->keyBy('id');
        $stops = $stopIds === [] ? collect() : DB::table('stops')
            ->whereIn('id', $stopIds)
            ->get(['id', 'stable_id', 'name', 'boarding_precision', 'boarding_verification_state'])
            ->keyBy('id');

        $items = array_values(array_map(function (array $item) use ($patterns, $stops): array {
            $item['affected_pairs'] = array_keys($item['affected_pairs']);
            $item['affected_od_pairs'] = count($item['affected_pairs']);
            if ($item['record_type'] === 'route_pattern') {
                $pattern = $patterns->get($item['record_id']);
                $item['stable_id'] = $pattern?->stable_id;
                $item['route_code'] = $pattern?->route_code;
                $item['direction'] = $pattern?->direction;
            } else {
                $stop = $stops->get($item['stop_id']);
                $pattern = $patterns->get($item['pattern_id']);
                $item['stable_id'] = $pattern?->stable_id.':'.$stop?->stable_id;
                $item['route_code'] = $pattern?->route_code;
                $item['direction'] = $pattern?->direction;
                $item['stop_name'] = $stop?->name;
                $item['boarding_precision'] = $stop?->boarding_precision;
                $item['boarding_verification_state'] = $stop?->boarding_verification_state;
                if ($item['claim_type'] === 'SEQUENCE') {
                    $item['stable_id'] .= ':'.$item['sequence'];
                }
            }
            $item['score'] = ($item['affected_od_pairs'] * 100)
                + ($item['affected_journeys'] * 10)
                + (($item['claim_type'] === 'BOARDING' && ($item['boarding_precision'] ?? 'UNKNOWN') === 'UNKNOWN') ? 30 : 0)
                + (($item['claim_type'] === 'SEQUENCE') ? 25 : 0)
                + (($item['claim_type'] === 'SERVICE') ? 20 : 0);

            return $item;
        }, $impact));
        usort($items, static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: strcmp((string) ($left['stable_id'] ?? ''), (string) ($right['stable_id'] ?? '')));
        $priorityItems = array_slice($items, 0, min(max($limit, 1), 100));

        $service = array_slice(array_values(array_filter($items, static fn (array $item): bool => $item['claim_type'] === 'SERVICE')), 0, 20);
        $sequence = array_slice(array_values(array_filter($items, static fn (array $item): bool => in_array($item['claim_type'], ['SEQUENCE', 'ALIGHTING'], true))), 0, 20);
        $boarding = array_slice(array_values(array_filter($items, static fn (array $item): bool => $item['claim_type'] === 'BOARDING')), 0, 50);
        $transfers = DB::table('transfer_relationships as tr')
            ->leftJoin('stops as fs', 'fs.id', '=', 'tr.from_stop_id')
            ->leftJoin('stops as ts', 'ts.id', '=', 'tr.to_stop_id')
            ->where('tr.is_demo', false)
            ->whereIn('tr.review_status', ['PENDING_REVIEW', 'NEEDS_RECHECK'])
            ->orderBy('tr.id')
            ->limit(50)
            ->get(['tr.stable_id', 'tr.review_status', 'tr.verification_state', 'fs.name as from_stop', 'ts.name as to_stop'])
            ->map(fn ($transfer): array => (array) $transfer)
            ->all();

        $fieldTasks = array_map(static function (array $item): array {
            $routeTarget = trim((string) ($item['route_code'] ?? '').' '.(string) ($item['direction'] ?? ''));
            $target = $item['stop_name'] ?? ($routeTarget !== '' ? $routeTarget : $item['stable_id']);
            $instruction = match ($item['claim_type']) {
                'SERVICE' => 'Confirm that the displayed route/pattern is operating in this direction.',
                'BOARDING' => 'Confirm the practical boarding area, side, and a useful landmark without guessing a bay.',
                'ALIGHTING' => 'Confirm where commuters can safely request/alight near the mapped stop.',
                default => 'Observe and record the ordered service relationship; do not infer unobserved stops.',
            };

            return [
                'id' => 'planned-batch018-'.$item['stable_id'],
                'status' => 'PLANNED',
                'claim_type' => $item['claim_type'],
                'target' => $target,
                'instruction' => $instruction,
                'affected_od_pairs' => $item['affected_od_pairs'],
                'is_evidence' => false,
            ];
        }, array_slice($priorityItems, 0, 20));

        return [
            'audit_pairs_used' => count(array_filter($auditResults, static fn (array $result): bool => ($result['status'] ?? null) === 'COMPLETE')),
            'top_service' => array_slice($service, 0, 20),
            'top_sequence' => array_slice($sequence, 0, 20),
            'top_boarding' => array_slice($boarding, 0, 50),
            'top_transfers' => $transfers,
            'field_survey_tasks' => $fieldTasks,
            'claims_promoted' => 0,
            'no_evidence_found' => 'Bounded source review remains required; this audit does not promote claims.',
        ];
    }

    /** @return array<string, mixed> */
    private function pedestrianGaps(): array
    {
        if (config('database.default') !== 'pgsql') {
            return ['status' => 'BLOCKED_POSTGIS_REQUIRED', 'total' => 0, 'items' => [], 'clusters' => []];
        }
        $sourceId = DB::table('provenance_sources')->where('stable_id', 'source-osm-cebu-batch017')->value('id');
        $dataset = DB::table('pedestrian_network_datasets')->where('is_active', true)->first();
        if ($sourceId === null || $dataset === null) {
            return ['status' => 'MISSING_SOURCE_OR_DATASET', 'total' => 0, 'items' => [], 'clusters' => []];
        }

        $rows = DB::select(
            'SELECT s.id, s.stable_id, s.name,
                ST_X(s.location::geometry) AS longitude,
                ST_Y(s.location::geometry) AS latitude,
                ST_Distance(s.location, nearest.location) AS nearest_node_meters
             FROM stops s
             LEFT JOIN LATERAL (
                SELECT n.location
                FROM pedestrian_network_nodes n
                WHERE n.dataset_id = ? AND n.is_walkable = true
                ORDER BY n.location <-> s.location
                LIMIT 1
             ) nearest ON true
             WHERE s.is_demo = false AND s.is_active = true AND s.provenance_source_id = ?
             ORDER BY s.id',
            [
                (int) $dataset->id,
                (int) $sourceId,
            ],
        );
        $items = [];
        $clusters = [];
        foreach ($rows as $row) {
            $distance = $row->nearest_node_meters === null ? null : (float) $row->nearest_node_meters;
            $outsideDataset = (float) $row->latitude < (float) $dataset->min_latitude
                || (float) $row->latitude > (float) $dataset->max_latitude
                || (float) $row->longitude < (float) $dataset->min_longitude
                || (float) $row->longitude > (float) $dataset->max_longitude;
            $classification = $outsideDataset
                ? 'OUTSIDE_DATASET'
                : ($distance !== null && $distance <= 25
                ? 'CONNECTED'
                : ($distance === null ? 'OSM_DATA_GAP' : 'PEDESTRIAN_ACCESS_UNCERTAIN'));
            $item = [
                'stable_id' => $row->stable_id,
                'name' => $row->name,
                'coordinate' => ['lat' => (float) $row->latitude, 'lng' => (float) $row->longitude],
                'nearest_node_meters' => $distance === null ? null : round($distance, 2),
                'classification' => $classification,
                'coordinate_moved' => false,
            ];
            if ($classification !== 'CONNECTED') {
                $items[] = $item;
                $clusterKey = round((float) $row->latitude, 3).':'.round((float) $row->longitude, 3);
                $clusters[$clusterKey] ??= ['key' => $clusterKey, 'count' => 0, 'stops' => []];
                $clusters[$clusterKey]['count']++;
                if (count($clusters[$clusterKey]['stops']) < 5) {
                    $clusters[$clusterKey]['stops'][] = $row->stable_id;
                }
            }
        }
        usort($items, static fn (array $left, array $right): int => (($right['nearest_node_meters'] ?? PHP_INT_MAX) <=> ($left['nearest_node_meters'] ?? PHP_INT_MAX)));
        $clusterRows = array_values(array_filter($clusters, static fn (array $cluster): bool => $cluster['count'] >= 2));
        usort($clusterRows, static fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return [
            'status' => 'COMPLETE',
            'source_stop_count' => count($rows),
            'total' => count($items),
            'unconnectable' => count($items),
            'connected_under_25m' => count($rows) - count($items),
            'classifications' => collect($items)->countBy('classification')->all(),
            'items' => $items,
            'clusters' => array_slice($clusterRows, 0, 30),
            'coordinates_moved' => 0,
            'snap_policy_meters' => 25,
        ];
    }

    /** @return array<string, mixed> */
    private function dataQuality(): array
    {
        $nearDuplicateStops = config('database.default') === 'pgsql'
            ? (int) DB::selectOne(
                "SELECT COUNT(*) AS count FROM stops a JOIN stops b ON a.id < b.id
                 WHERE a.is_demo=false AND b.is_demo=false AND a.is_active=true AND b.is_active=true
                   AND a.location IS NOT NULL AND b.location IS NOT NULL
                   AND ST_DWithin(a.location,b.location,30)
                   AND lower(regexp_replace(coalesce(a.name,''),'[^a-z0-9]','','g')) =
                       lower(regexp_replace(coalesce(b.name,''),'[^a-z0-9]','','g'))",
            )->count
            : 0;
        $aliasCollisions = DB::table('place_aliases')
            ->where('is_active', true)
            ->select('normalized_alias')
            ->groupBy('normalized_alias')
            ->havingRaw('COUNT(DISTINCT place_id) > 1')
            ->count();
        $routeCollisions = DB::table('routes')
            ->where('is_active', true)
            ->where('is_demo', false)
            ->select('city_id', 'code')
            ->groupBy('city_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $emptyPatterns = DB::table('route_patterns as rp')
            ->where('rp.is_active', true)
            ->where('rp.is_demo', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('route_pattern_stops as rps')->whereColumn('rps.route_pattern_id', 'rp.id'))
            ->count();
        $duplicateSequences = DB::table('route_pattern_stops')
            ->select('route_pattern_id', 'sequence')
            ->groupBy('route_pattern_id', 'sequence')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $invalidGeometry = config('database.default') === 'pgsql'
            ? DB::table('route_patterns')->whereNotNull('geometry')->whereRaw('ST_SRID(geometry) <> 4326 OR NOT ST_IsValid(geometry)')->count()
            : 0;

        return [
            'near_duplicate_stop_candidates' => $nearDuplicateStops,
            'auto_merged_stops' => 0,
            'alias_collisions' => $aliasCollisions,
            'route_collisions' => $routeCollisions,
            'pattern_defects' => [
                'empty_patterns' => $emptyPatterns,
                'duplicate_sequences' => $duplicateSequences,
                'invalid_geometry' => $invalidGeometry,
            ],
        ];
    }
}
