<?php

namespace App\Services\Evidence;

use App\Enums\VerificationState;
use App\Models\Place;
use App\Services\Routing\JourneyPlanner;
use Illuminate\Support\Facades\DB;

final class EvidencePriorityEngine
{
    public function __construct(
        private readonly JourneyPlanner $journeyPlanner,
        private readonly EvidenceBottleneckAnalyzer $bottleneckAnalyzer,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(?int $limit = null, ?int $maxPairs = null, ?int $maxTransfers = null): array
    {
        $places = Place::query()
            ->with('provenanceSource')
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value])
            ->when(config('database.default') === 'pgsql', fn ($builder) => $builder->addSelect([
                'places.*',
                DB::raw('ST_AsText(places.location) AS location_wkt'),
            ]))
            ->orderBy('city_id')
            ->orderBy('id')
            ->limit((int) config('biyahe.evidence.priority.max_places', 40))
            ->get();
        $maxPairs ??= (int) config('biyahe.evidence.priority.max_pairs', 60);
        $maxTransfers ??= (int) config('biyahe.evidence.priority.max_transfers', 2);
        $maxPairs = min(max($maxPairs, 1), (int) config('biyahe.evidence.priority.max_pairs', 60));
        $maxTransfers = min(max($maxTransfers, 0), (int) config('biyahe.routing.public_max_transfers', 2));
        $items = [];
        $pairsExamined = 0;
        $journeysExamined = 0;
        $noKnownJourneys = 0;
        $errors = [];

        foreach ($places as $origin) {
            foreach ($places as $destination) {
                if ($origin->getKey() === $destination->getKey()) {
                    continue;
                }
                if ($pairsExamined >= $maxPairs) {
                    break 2;
                }
                $pairsExamined++;
                try {
                    $search = $this->journeyPlanner->plan($origin, $destination, null, null, $maxTransfers);
                } catch (\Throwable $exception) {
                    $errors[] = [
                        'origin' => $origin->slug,
                        'destination' => $destination->slug,
                        'message' => $exception->getMessage(),
                    ];

                    continue;
                }
                if ($search->journeys === []) {
                    $noKnownJourneys++;

                    continue;
                }
                foreach ($search->journeys as $index => $journey) {
                    $journeysExamined++;
                    foreach ($this->bottleneckAnalyzer->analyze($journey) as $gap) {
                        $key = implode(':', [
                            $gap['type'] ?? 'UNKNOWN',
                            $gap['record_type'] ?? 'unknown',
                            $gap['record_id'] ?? 'none',
                            $gap['fact'] ?? 'all',
                        ]);
                        if (! isset($items[$key])) {
                            $items[$key] = [
                                'type' => $gap['type'] ?? 'UNKNOWN',
                                'record_type' => $gap['record_type'] ?? 'unknown',
                                'record_id' => $gap['record_id'] ?? null,
                                'stable_id' => $gap['stable_id'] ?? null,
                                'fact' => $gap['fact'] ?? null,
                                'affected_journeys' => 0,
                                'affected_od_pairs' => [],
                                'recommended_journeys' => 0,
                                'current_trust_states' => [],
                                'freshness_states' => [],
                                'reasons' => [],
                            ];
                        }
                        $items[$key]['affected_journeys']++;
                        $items[$key]['affected_od_pairs'][$origin->slug.'->'.$destination->slug] = true;
                        if ($index === 0) {
                            $items[$key]['recommended_journeys']++;
                        }
                        $trust = (string) ($gap['current_trust'] ?? 'UNKNOWN');
                        $freshness = (string) ($gap['freshness'] ?? 'UNKNOWN_FRESHNESS');
                        $items[$key]['current_trust_states'][$trust] = ($items[$key]['current_trust_states'][$trust] ?? 0) + 1;
                        $items[$key]['freshness_states'][$freshness] = ($items[$key]['freshness_states'][$freshness] ?? 0) + 1;
                        $reason = (string) ($gap['reason'] ?? 'Evidence needs review.');
                        $items[$key]['reasons'][$reason] = true;
                    }
                }
            }
        }

        $weights = config('biyahe.evidence.priority.weights', []);
        $items = array_values(array_map(function (array $item) use ($weights): array {
            $item['affected_od_pairs'] = array_keys($item['affected_od_pairs']);
            $item['affected_od_pairs_count'] = count($item['affected_od_pairs']);
            $item['current_trust_states'] = $item['current_trust_states'];
            $trustStates = array_keys($item['current_trust_states']);
            sort($trustStates);
            $item['current_trust'] = implode('/', $trustStates);
            $item['freshness_states'] = $item['freshness_states'];
            $item['reasons'] = array_keys($item['reasons']);
            $item['score'] = ($item['affected_od_pairs'] ? count($item['affected_od_pairs']) * (int) ($weights['od_pair'] ?? 100) : 0)
                + $item['affected_journeys'] * (int) ($weights['journey'] ?? 10)
                + $item['recommended_journeys'] * (int) ($weights['recommended'] ?? 25)
                + (($item['type'] ?? '') === 'TRANSFER' ? (int) ($weights['transfer'] ?? 20) : 0)
                + (($item['type'] ?? '') === 'BOARDING' ? (int) ($weights['boarding'] ?? 15) : 0)
                + (($item['current_trust_states']['MIXED'] ?? 0) + ($item['current_trust_states']['UNVERIFIED'] ?? 0)) * (int) ($weights['trust_bottleneck'] ?? 10)
                + (($item['freshness_states']['AGING'] ?? 0) + ($item['freshness_states']['STALE'] ?? 0)) * (int) ($weights['freshness'] ?? 15)
                + ($item['freshness_states']['UNKNOWN_FRESHNESS'] ?? 0) * (int) ($weights['unknown_freshness'] ?? 20);

            return $item;
        }, $items));
        usort($items, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: strcmp((string) $left['type'], (string) $right['type'])
                ?: strcmp((string) ($left['stable_id'] ?? ''), (string) ($right['stable_id'] ?? ''))
                ?: (($left['record_id'] ?? 0) <=> ($right['record_id'] ?? 0));
        });
        $limit ??= (int) config('biyahe.evidence.priority.max_items', 20);
        $items = array_slice($items, 0, min(max($limit, 1), 100));
        foreach ($items as $index => &$item) {
            $item['rank'] = $index + 1;
            $item['reason'] = implode('; ', array_slice($item['reasons'], 0, 3));
        }
        unset($item);

        return [
            'pairs_examined' => $pairsExamined,
            'journeys_examined' => $journeysExamined,
            'no_known_journeys' => $noKnownJourneys,
            'errors' => $errors,
            'items' => $items,
        ];
    }
}
