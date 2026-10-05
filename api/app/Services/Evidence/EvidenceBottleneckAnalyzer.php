<?php

namespace App\Services\Evidence;

use App\Enums\JourneyLegType;
use App\Enums\JourneyTrustState;
use App\Services\Routing\Journey;

final class EvidenceBottleneckAnalyzer
{
    /** @return list<array<string, mixed>> */
    public function analyze(Journey $journey): array
    {
        $gaps = [];
        $this->addPlaceGap($gaps, $journey->origin);
        $this->addPlaceGap($gaps, $journey->destination);
        foreach ($journey->legs as $leg) {
            if ($leg->type === JourneyLegType::RIDE) {
                $this->addModelGap($gaps, $leg->evidence['route'] ?? null, 'ROUTE_IDENTITY', 'Route identity evidence is not fully verified.');
                $this->addModelGap($gaps, $leg->evidence['pattern'] ?? null, 'ROUTE_PATTERN', 'Directional pattern evidence is not fully verified.');
                $this->addAssociationGaps($gaps, $leg->evidence['boarding']['association'] ?? null, 'boarding');
                $this->addAssociationGaps($gaps, $leg->evidence['alighting']['association'] ?? null, 'alighting');
                $this->addBoardingGap($gaps, $leg->evidence['boarding']['stop'] ?? null);
                $this->addStopLocationGap($gaps, $leg->evidence['boarding']['stop'] ?? null);
                $this->addStopLocationGap($gaps, $leg->evidence['alighting']['stop'] ?? null);
            }
            if ($leg->type === JourneyLegType::TRANSFER_WALK) {
                $relationship = $leg->evidence['relationship'] ?? null;
                if (is_array($relationship) && ($relationship['type'] ?? null) === 'TRANSFER') {
                    $state = $relationship['verification_state'] ?? null;
                    $reviewStatus = $relationship['review_status'] ?? null;
                    if ($state !== 'VERIFIED' || $reviewStatus !== 'SUPPORTED') {
                        $this->addGap($gaps, [
                            'type' => 'TRANSFER',
                            'record_type' => 'transfer_relationship',
                            'record_id' => $relationship['id'] ?? null,
                            'stable_id' => $relationship['stable_id'] ?? null,
                            'current_trust' => $state ?? 'UNKNOWN',
                            'freshness' => $relationship['freshness'] ?? 'UNKNOWN_FRESHNESS',
                            'reason' => 'Transfer relationship or review status is not fully verified.',
                        ]);
                    }
                }
            }
        }

        return array_values($gaps);
    }

    /** @param array<string, array<string, mixed>> $gaps @param array<string, mixed> $place */
    private function addPlaceGap(array &$gaps, array $place): void
    {
        $verification = $place['verification'] ?? null;
        if (! is_array($verification) || ($verification['state'] ?? null) === JourneyTrustState::VERIFIED->value) {
            return;
        }
        $this->addGap($gaps, [
            'type' => 'PLACE_LOCATION',
            'record_type' => 'place',
            'record_id' => $place['id'] ?? null,
            'stable_id' => $place['stable_id'] ?? null,
            'current_trust' => $verification['state'] ?? 'UNKNOWN',
            'freshness' => $place['location_freshness'] ?? 'UNKNOWN_FRESHNESS',
            'reason' => 'The origin or destination landmark location is not fully verified.',
        ]);
    }

    /** @param array<string, array<string, mixed>> $gaps */
    private function addModelGap(array &$gaps, mixed $evidence, string $type, string $reason): void
    {
        if (! is_array($evidence) || ($evidence['state'] ?? null) === JourneyTrustState::VERIFIED->value) {
            return;
        }
        $this->addGap($gaps, [
            'type' => $type,
            'record_type' => 'transport_model',
            'record_id' => $evidence['id'] ?? null,
            'stable_id' => $evidence['stable_id'] ?? null,
            'current_trust' => $evidence['state'] ?? 'UNKNOWN',
            'freshness' => $evidence['freshness'] ?? 'UNKNOWN_FRESHNESS',
            'reason' => $reason,
        ]);
    }

    /** @param array<string, array<string, mixed>> $gaps */
    private function addAssociationGaps(array &$gaps, mixed $evidence, string $legPart): void
    {
        if (! is_array($evidence)) {
            return;
        }
        $criticalFacts = $legPart === 'boarding'
            ? ['service', 'sequence', 'boarding']
            : ['service', 'sequence', 'alighting'];
        foreach ($criticalFacts as $fact) {
            $factEvidence = $evidence[$fact] ?? null;
            if (! is_array($factEvidence) || ($factEvidence['state'] ?? null) === JourneyTrustState::VERIFIED->value) {
                continue;
            }
            $this->addGap($gaps, [
                'type' => 'PATTERN_STOP',
                'record_type' => 'route_pattern_stop',
                'record_id' => $evidence['id'] ?? null,
                'stable_id' => $evidence['stable_id'] ?? null,
                'fact' => $fact,
                'leg_part' => $legPart,
                'current_trust' => $factEvidence['state'] ?? 'UNKNOWN',
                'freshness' => $factEvidence['freshness'] ?? 'UNKNOWN_FRESHNESS',
                'reason' => ucfirst($fact).' evidence for this route-stop association is not fully verified.',
            ]);
        }
    }

    /** @param array<string, array<string, mixed>> $gaps */
    private function addBoardingGap(array &$gaps, mixed $evidence, bool $boarding = true): void
    {
        if (! is_array($evidence)) {
            return;
        }
        $state = $evidence['boarding_verification_state'] ?? null;
        $precision = $evidence['boarding_precision'] ?? null;
        if ($state === JourneyTrustState::VERIFIED->value) {
            return;
        }
        $this->addGap($gaps, [
            'type' => 'BOARDING',
            'record_type' => 'stop',
            'record_id' => $evidence['id'] ?? null,
            'stable_id' => $evidence['stable_id'] ?? null,
            'current_trust' => $state ?? 'UNKNOWN',
            'freshness' => $evidence['freshness'] ?? 'UNKNOWN_FRESHNESS',
            'reason' => $boarding
                ? 'The boarding location or its precision is not fully verified.'
                : 'The stop-level boarding detail is not fully verified.',
        ]);
    }

    /** @param array<string, array<string, mixed>> $gaps */
    private function addStopLocationGap(array &$gaps, mixed $evidence): void
    {
        if (! is_array($evidence) || ($evidence['state'] ?? null) === JourneyTrustState::VERIFIED->value) {
            return;
        }
        $this->addGap($gaps, [
            'type' => 'STOP_LOCATION',
            'record_type' => 'stop',
            'record_id' => $evidence['id'] ?? null,
            'stable_id' => $evidence['stable_id'] ?? null,
            'current_trust' => $evidence['state'] ?? 'UNKNOWN',
            'freshness' => $evidence['location_freshness'] ?? 'UNKNOWN_FRESHNESS',
            'reason' => 'The stop coordinate is not fully verified.',
        ]);
    }

    /** @param array<string, array<string, mixed>> $gaps @param array<string, mixed> $gap */
    private function addGap(array &$gaps, array $gap): void
    {
        $key = implode(':', [
            $gap['type'] ?? 'UNKNOWN',
            $gap['record_type'] ?? 'unknown',
            $gap['record_id'] ?? 'none',
            $gap['fact'] ?? 'all',
        ]);
        $gaps[$key] ??= $gap;
    }
}
