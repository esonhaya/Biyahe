<?php

namespace App\Services\FieldSurveys;

use App\Services\Evidence\NetworkIntelligenceAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class Batch019GroundTruthCampaign
{
    public const VERSION = 'batch019-ground-truth-v1';

    /** @return array<string, mixed> */
    public function build(array $checkpoint, int $limit = 100): array
    {
        $results = is_array($checkpoint['results'] ?? null) ? $checkpoint['results'] : [];
        $intelligence = app(NetworkIntelligenceAudit::class)->analyze($results, min(max($limit, 1), 100));
        $claims = $this->claims($intelligence['evidence_priorities'] ?? [], min(max($limit, 1), 100));
        $missions = $this->missions($claims);

        return [
            'campaign_version' => self::VERSION,
            'network_fingerprint' => $checkpoint['network_fingerprint'] ?? null,
            'source_review' => $this->sourceReview(),
            'claim_count' => count($claims),
            'claims' => $claims,
            'mission_count' => count($missions),
            'missions' => $missions,
            'promotion_gate' => [
                'automatic_promotions' => 0,
                'required_for_promotion' => [
                    'claim-specific supporting source or reviewed field observation',
                    'narrow supported scope recorded',
                    'reviewer decision recorded through the existing evidence workflow',
                    'no inference from GPS, proximity, route geometry, or report volume',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function claims(array $priority, int $limit): array
    {
        $raw = [];
        foreach ([
            'top_service' => 'PATTERN_SERVICE',
            'top_sequence' => 'SEQUENCE',
            'top_boarding' => 'BOARDING',
        ] as $field => $type) {
            foreach (($priority[$field] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $raw[] = [...$item, 'claim_type' => $type];
            }
        }
        foreach (($priority['top_transfers'] ?? []) as $item) {
            if (is_array($item)) {
                $raw[] = [...$item, 'claim_type' => 'TRANSFER'];
            }
        }

        $raw = array_slice($raw, 0, min(max($limit * 2, 1), 240));
        $patternIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) ($item['pattern_id'] ?? $item['record_id'] ?? 0),
            $raw,
        ))));
        $stopIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) ($item['stop_id'] ?? 0),
            $raw,
        ))));
        $patterns = $patternIds === [] ? collect() : DB::table('route_patterns as rp')
            ->join('routes as r', 'r.id', '=', 'rp.route_id')
            ->leftJoin('provenance_sources as ps', 'ps.id', '=', 'rp.provenance_source_id')
            ->whereIn('rp.id', $patternIds)
            ->get(['rp.id', 'rp.stable_id', 'rp.direction', 'rp.verification_state', 'r.code as route_code', 'ps.stable_id as source_stable_id'])
            ->keyBy('id');
        $stops = $stopIds === [] ? collect() : DB::table('stops as s')
            ->leftJoin('provenance_sources as ps', 'ps.id', '=', 's.provenance_source_id')
            ->whereIn('s.id', $stopIds)
            ->get(['s.id', 's.stable_id', 's.name', 's.boarding_precision', 's.boarding_verification_state', 'ps.stable_id as source_stable_id'])
            ->keyBy('id');
        $associations = ($patternIds !== [] && $stopIds !== [])
            ? DB::table('route_pattern_stops as rps')
                ->leftJoin('provenance_sources as service_ps', 'service_ps.id', '=', 'rps.service_provenance_source_id')
                ->leftJoin('provenance_sources as sequence_ps', 'sequence_ps.id', '=', 'rps.sequence_provenance_source_id')
                ->leftJoin('provenance_sources as boarding_ps', 'boarding_ps.id', '=', 'rps.boarding_provenance_source_id')
                ->leftJoin('provenance_sources as alighting_ps', 'alighting_ps.id', '=', 'rps.alighting_provenance_source_id')
                ->whereIn('rps.route_pattern_id', $patternIds)
                ->whereIn('rps.stop_id', $stopIds)
                ->get([
                    'rps.id', 'rps.stable_id', 'rps.route_pattern_id', 'rps.stop_id', 'rps.sequence',
                    'rps.service_verification_state', 'rps.sequence_verification_state',
                    'rps.boarding_verification_state', 'rps.alighting_verification_state',
                    'service_ps.stable_id as service_source', 'sequence_ps.stable_id as sequence_source',
                    'boarding_ps.stable_id as boarding_source', 'alighting_ps.stable_id as alighting_source',
                ])
                ->keyBy(fn ($row): string => $row->route_pattern_id.':'.$row->stop_id)
            : collect();
        $transfers = DB::table('transfer_relationships as tr')
            ->leftJoin('provenance_sources as ps', 'ps.id', '=', 'tr.provenance_source_id')
            ->where('tr.is_demo', false)
            ->get(['tr.id', 'tr.stable_id', 'tr.verification_state', 'tr.review_status', 'ps.stable_id as source_stable_id'])
            ->keyBy('stable_id');

        $claims = [];
        foreach ($raw as $rank => $item) {
            $type = (string) ($item['claim_type'] ?? 'UNKNOWN');
            $patternId = (int) ($item['pattern_id'] ?? $item['record_id'] ?? 0);
            $stopId = (int) ($item['stop_id'] ?? 0);
            $pattern = $patterns->get($patternId);
            $stop = $stops->get($stopId);
            $transfer = $transfers->get((string) ($item['stable_id'] ?? ''));
            $stableId = $type === 'TRANSFER'
                ? (string) ($item['stable_id'] ?? 'transfer-unknown')
                : implode(':', array_filter([
                    $pattern?->stable_id ?? (string) ($item['stable_id'] ?? 'pattern-unknown'),
                    $stop?->stable_id,
                    $type,
                    isset($item['sequence']) ? (string) (int) $item['sequence'] : null,
                ], static fn (?string $value): bool => $value !== null && $value !== ''));
            $claimKey = $type.':'.$stableId;
            if (isset($claims[$claimKey])) {
                $claims[$claimKey]['affected_od_pairs'] = array_values(array_unique([
                    ...$claims[$claimKey]['affected_od_pairs'],
                    ...($item['affected_pairs'] ?? $item['affected_od_pairs'] ?? []),
                ]));
                continue;
            }
            $currentStatus = $type === 'TRANSFER'
                ? ($transfer->verification_state ?? 'UNKNOWN')
                : ($type === 'PATTERN_SERVICE'
                    ? ($pattern->verification_state ?? 'UNKNOWN')
                    : ($associations->get($patternId.':'.$stopId)?->{strtolower($type).'_verification_state'} ?? 'UNKNOWN'));
            $knownSources = $this->knownSources($type, $pattern, $stop, $associations->get($patternId.':'.$stopId), $transfer);
            $researchStatus = $this->researchStatus($type, $pattern?->route_code, $stop?->name);
            $claims[$claimKey] = [
                'claim_id' => 'b019-'.substr(hash('sha256', $claimKey), 0, 20),
                'claim_type' => $type,
                'target_entity' => [
                    'pattern_id' => $pattern?->id,
                    'pattern_stable_id' => $pattern?->stable_id,
                    'route_code' => $pattern?->route_code,
                    'direction' => $pattern?->direction,
                    'stop_id' => $stop?->id,
                    'stop_stable_id' => $stop?->stable_id,
                    'stop_name' => $stop?->name,
                    'transfer_stable_id' => $transfer?->stable_id,
                ],
                'current_status' => $currentStatus,
                'affected_od_pairs' => array_values(array_unique($item['affected_pairs'] ?? $item['affected_od_pairs'] ?? [])),
                'journeys_affected' => (int) ($item['affected_journeys'] ?? 0),
                'priority_score' => (int) ($item['score'] ?? 0),
                'priority_rank' => $rank + 1,
                'known_sources' => $knownSources,
                'research_status' => $researchStatus,
                'promotion' => 'NO_AUTOMATIC_PROMOTION',
            ];
        }

        $claims = array_values($claims);
        usort($claims, static fn (array $left, array $right): int => ($right['priority_score'] <=> $left['priority_score'])
            ?: strcmp((string) $left['claim_id'], (string) $right['claim_id']));
        foreach ($claims as $index => &$claim) {
            $claim['priority_rank'] = $index + 1;
        }
        unset($claim);

        return array_slice($claims, 0, $limit);
    }

    /** @return list<string> */
    private function knownSources(string $type, mixed $pattern, mixed $stop, mixed $association, mixed $transfer): array
    {
        $sources = [];
        foreach ([
            $pattern->source_stable_id ?? null,
            $stop->source_stable_id ?? null,
            $association?->service_source,
            $association?->sequence_source,
            $association?->boarding_source,
            $association?->alighting_source,
            $transfer->source_stable_id ?? null,
        ] as $source) {
            if (is_string($source) && $source !== '') {
                $sources[] = $source;
            }
        }
        if ($type === 'PATTERN_SERVICE' && $pattern?->route_code === '13B') {
            $sources[] = 'source-osm-cebu-batch017';
            $sources[] = 'source-osm-metro-cebu-wiki-2026-review';
        }

        return array_values(array_unique($sources));
    }

    private function researchStatus(string $type, ?string $routeCode, ?string $stopName): string
    {
        if ($routeCode === '13B' && $type === 'PATTERN_SERVICE') {
            return 'REMOTE_PARTIAL_NO_CURRENT_SERVICE_PROMOTION';
        }
        if ($routeCode === '13B' && $stopName !== null && str_contains(strtolower($stopName), 'carbon')) {
            return 'FIELD_REQUIRED';
        }
        if ($type === 'TRANSFER') {
            return 'FIELD_REQUIRED';
        }

        return in_array($type, ['BOARDING', 'ALIGHTING', 'SEQUENCE'], true)
            ? 'FIELD_REQUIRED'
            : 'REMOTE_REVIEW_QUEUE';
    }

    /** @return list<array<string, mixed>> */
    private function missions(array $claims): array
    {
        $groups = [];
        foreach ($claims as $claim) {
            $text = strtolower(implode(' ', array_filter([
                $claim['target_entity']['route_code'] ?? null,
                $claim['target_entity']['stop_name'] ?? null,
                $claim['target_entity']['direction'] ?? null,
            ])));
            $group = match (true) {
                str_contains($text, 'carbon') => ['carbon', 'Carbon Market', 'Carbon Market Unit 3', 'route and direction signs, practical loading area, stop order'],
                str_contains($text, 'ayala') => ['ayala', 'Ayala', 'Ayala boarding area', 'route signs, waiting area, boarding side'],
                str_contains($text, 'it park') || str_contains($text, 'lahug') => ['it-park-lahug', 'IT Park / Lahug', 'IT Park or Lahug', 'route signs, boarding area, destination cue'],
                str_contains($text, 'talamban') => ['talamban', 'Talamban', 'Talamban terminal/boarding area', 'route signs, direction, sequence and alighting opportunities'],
                str_contains($text, 'mandaue') || str_contains($text, 'ouano') => ['mandaue', 'Mandaue', 'Mandaue corridor', 'interchange practicality, walking side and route signs'],
                str_contains($text, 'pardo') || str_contains($text, 'bulacao') || str_contains($text, 'inayawan') => ['south', 'South Cebu City', 'Pardo / Bulacao corridor', 'boarding area, direction and alighting sequence'],
                default => ['core', 'Cebu core', 'the mapped target area', 'route identity, direction, boarding/alighting and sequence'],
            };
            $groups[$group[0]] ??= [
                'id' => 'b019-mission-'.$group[0],
                'status' => 'PLANNED',
                'title' => $group[1].' ground-truth mission',
                'start_area' => $group[2],
                'route_scope' => [],
                'claim_ids' => [],
                'observe' => $group[3],
                'records_needed' => ['route code and signboard/destination cue', 'direction', 'practical boarding area and landmark', 'ordered service points', 'alighting opportunity and side', 'timestamp and evidence reference'],
                'safety_notes' => 'Do not record unrelated movement. Do not infer a stop from proximity. Do not treat a planned mission as evidence until reviewed.',
                'is_evidence' => false,
            ];
            $groups[$group[0]]['claim_ids'][] = $claim['claim_id'];
            if (($claim['target_entity']['route_code'] ?? null) !== null) {
                $groups[$group[0]]['route_scope'][] = $claim['target_entity']['route_code'];
            }
        }
        foreach ($groups as &$mission) {
            $mission['route_scope'] = array_values(array_unique($mission['route_scope']));
            $mission['claim_ids'] = array_values(array_unique($mission['claim_ids']));
        }
        unset($mission);
        ksort($groups);

        return array_values($groups);
    }

    /** @return list<array<string, mixed>> */
    private function sourceReview(): array
    {
        return [
            [
                'source_id' => 'source-osm-metro-cebu-wiki-2026-review',
                'title' => 'Public transportation in Metro Cebu',
                'publisher' => 'OpenStreetMap Wiki',
                'url' => 'https://wiki.openstreetmap.org/wiki/Public_transportation_in_Metro_Cebu',
                'tier' => 3,
                'checked_at' => '2026-10-07',
                'scope' => ['ROUTE_IDENTITY', 'GENERAL_CORRIDOR'],
                'result' => 'MAPPED_ATLAS_REFERENCE_ONLY',
                'limitation' => 'The page says mapping is in progress and stops are missing; it does not verify current dispatch, stop sequence, boarding or alighting practice.',
            ],
            [
                'source_id' => 'source-cebu-city-ccto-sinulog-2020-review',
                'title' => 'Cebu City temporary Sinulog sa Kabataan rerouting notice',
                'publisher' => 'Cebu City Government / CCTO',
                'url' => 'https://www.cebucity.gov.ph/traffic-re-routing-plan-during-the-sinulog-sa-kabataan-dakbayan-2020/',
                'tier' => 1,
                'checked_at' => '2026-10-07',
                'scope' => ['HISTORICAL_TEMPORARY_REROUTE'],
                'result' => 'HISTORICAL_ONLY',
                'limitation' => 'A historical event notice cannot establish current 13B service, boarding or sequence.',
            ],
            [
                'source_id' => 'source-cebu-daily-news-route-guide-2026-review',
                'title' => 'Cebu City jeepney route-code guide',
                'publisher' => 'CDN Digital / Cebu Daily News',
                'url' => 'https://cebudailynews.inquirer.net/728043/lost-in-cebu-heres-a-guide-to-cebu-citys-jeepney-route-codes',
                'tier' => 3,
                'checked_at' => '2026-10-07',
                'scope' => ['ROUTE_IDENTITY', 'GENERAL_CORRIDOR'],
                'result' => 'NOT_ACCEPTED_FOR_NEW_PROMOTION',
                'limitation' => 'The page could not be reviewed in this run; a search snippet is not treated as evidence.',
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    public function writeAtomic(string $path, array $payload): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Output path is invalid.');
        }
        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.tmp.'.getmypid();
        file_put_contents($temporary, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($temporary, $path);
    }
}
