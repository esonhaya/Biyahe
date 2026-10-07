<?php

namespace App\Models;

use App\Enums\FieldMissionClaimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldMissionClaim extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'affected_od_pairs' => 'array',
            'metadata' => 'array',
            'current_status' => FieldMissionClaimStatus::class,
            'journeys_affected' => 'integer',
            'priority_score' => 'integer',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(FieldMission::class, 'field_mission_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(FieldObservation::class, 'field_mission_claim_id');
    }
}
