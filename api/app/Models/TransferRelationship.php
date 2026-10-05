<?php

namespace App\Models;

use App\Enums\TransferReviewStatus;
use App\Enums\VerificationState;
use App\Services\Routing\TransferReviewFingerprint;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferRelationship extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'distance_meters' => 'float',
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'verification_state' => VerificationState::class,
            'review_status' => TransferReviewStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TransferRelationship $relationship): void {
            if (
                $relationship->review_status === TransferReviewStatus::REJECTED
                && ($relationship->isDirty('review_status') || $relationship->isDirty(['from_stop_id', 'to_stop_id']))
            ) {
                $relationship->reviewed_at = now();
                $relationship->review_fingerprint = app(TransferReviewFingerprint::class)->forPair(
                    (int) $relationship->from_stop_id,
                    (int) $relationship->to_stop_id,
                );
            }
        });
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function fromStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'from_stop_id');
    }

    public function toStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'to_stop_id');
    }

    public function fromRoutePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class, 'from_route_pattern_id');
    }

    public function toRoutePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class, 'to_route_pattern_id');
    }

    public function provenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class);
    }
}
