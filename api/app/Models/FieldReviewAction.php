<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldReviewAction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(FieldMission::class, 'field_mission_id');
    }

    public function missionClaim(): BelongsTo
    {
        return $this->belongsTo(FieldMissionClaim::class, 'field_mission_claim_id');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(FieldObservation::class, 'field_observation_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(FieldEvidenceClaim::class, 'field_evidence_claim_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
