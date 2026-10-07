<?php

namespace App\Services\Disruptions;

use App\Enums\DisruptionStatus;
use App\Models\Disruption;
use App\Models\DisruptionReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DisruptionLifecycleService
{
    public function transition(Disruption $disruption, DisruptionStatus $status, ?User $actor, ?string $reason = null): Disruption
    {
        if ($status === DisruptionStatus::ACTIVE && $disruption->provenance_source_id === null) {
            throw new \InvalidArgumentException('An advisory needs provenance before activation.');
        }
        if ($status === DisruptionStatus::SCHEDULED && $disruption->starts_at === null) {
            throw new \InvalidArgumentException('A scheduled advisory needs a start time.');
        }
        if ($disruption->starts_at !== null && $disruption->ends_at !== null && $disruption->ends_at->lt($disruption->starts_at)) {
            throw new \InvalidArgumentException('An advisory end time cannot precede its start time.');
        }

        return DB::transaction(function () use ($disruption, $status, $actor, $reason): Disruption {
            $from = $disruption->status?->value ?? (string) $disruption->status;
            $disruption->status = $status;
            if ($status === DisruptionStatus::ACTIVE) {
                $disruption->reviewed_by_user_id = $actor?->getKey() ?? $disruption->reviewed_by_user_id;
                $disruption->reviewed_at = now();
            }
            if ($status === DisruptionStatus::RESOLVED || $status === DisruptionStatus::REJECTED) {
                $disruption->reviewed_by_user_id = $actor?->getKey() ?? $disruption->reviewed_by_user_id;
                $disruption->reviewed_at = now();
            }
            if ($reason !== null) {
                $disruption->review_notes = $reason;
            }
            $disruption->save();
            DisruptionReview::query()->create([
                'disruption_id' => $disruption->getKey(),
                'actor_user_id' => $actor?->getKey(),
                'from_status' => $from,
                'to_status' => $status->value,
                'reason' => $reason,
            ]);

            return $disruption->fresh();
        });
    }
}
