<?php

namespace App\Services\Evidence;

use App\Enums\EvidenceClaimType;
use App\Enums\EvidenceFreshnessState;
use App\Models\ProvenanceSource;
use Carbon\CarbonInterface;

final class EvidenceFreshnessPolicy
{
    public function classify(?ProvenanceSource $source, string|EvidenceClaimType $claim): EvidenceFreshnessState
    {
        if (! $source instanceof ProvenanceSource || $source->checked_at === null) {
            return EvidenceFreshnessState::UNKNOWN_FRESHNESS;
        }

        if ($source->superseded_by_id !== null) {
            return EvidenceFreshnessState::STALE;
        }

        $policy = $this->policy($claim);
        $checkedAt = $source->checked_at;
        if (! $checkedAt instanceof CarbonInterface) {
            return EvidenceFreshnessState::UNKNOWN_FRESHNESS;
        }

        if ($checkedAt->greaterThanOrEqualTo(now()->subDays($policy['current_days']))) {
            return EvidenceFreshnessState::CURRENT;
        }

        if ($checkedAt->greaterThanOrEqualTo(now()->subDays($policy['aging_days']))) {
            return EvidenceFreshnessState::AGING;
        }

        return EvidenceFreshnessState::STALE;
    }

    /** @return array{current_days: int, aging_days: int} */
    public function policy(string|EvidenceClaimType $claim): array
    {
        $key = $claim instanceof EvidenceClaimType ? $claim->value : $claim;
        if (str_starts_with($key, 'PATTERN_STOP_')) {
            $key = 'PATTERN_STOP';
        }
        $configured = config('biyahe.evidence.freshness.'.$key);
        $default = config('biyahe.evidence.freshness.default', ['current_days' => 180, 'aging_days' => 365]);

        return [
            'current_days' => (int) ($configured['current_days'] ?? $default['current_days']),
            'aging_days' => (int) ($configured['aging_days'] ?? $default['aging_days']),
        ];
    }

    /** @return array{rank: int, label: string} */
    public function sourceQuality(?ProvenanceSource $source): array
    {
        $type = $source?->source_type;
        $quality = config('biyahe.evidence.source_quality.'.$type);

        return [
            'rank' => (int) ($quality['rank'] ?? 99),
            'label' => (string) ($quality['label'] ?? 'Unclassified source'),
        ];
    }

    public function isUsableForVerifiedClaim(?ProvenanceSource $source, string|EvidenceClaimType $claim): bool
    {
        return in_array($this->classify($source, $claim), [
            EvidenceFreshnessState::CURRENT,
            EvidenceFreshnessState::AGING,
        ], true)
            && $source?->conflict_status?->value !== 'OPEN'
            && $source?->superseded_by_id === null;
    }
}
