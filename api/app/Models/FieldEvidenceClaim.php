<?php

namespace App\Models;

use App\Enums\EvidenceClaimType;
use App\Enums\FieldClaimComparison;
use App\Enums\FieldClaimStatus;
use App\Enums\FieldClaimTargetType;
use Database\Factories\FieldEvidenceClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class FieldEvidenceClaim extends Model
{
    /** @use HasFactory<FieldEvidenceClaimFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'proposed_by_user_id',
        'attached_by_user_id',
        'attached_at',
    ];

    protected function casts(): array
    {
        return [
            'claim_type' => EvidenceClaimType::class,
            'target_type' => FieldClaimTargetType::class,
            'comparison' => FieldClaimComparison::class,
            'review_status' => FieldClaimStatus::class,
            'reviewed_at' => 'datetime',
            'attached_at' => 'datetime',
        ];
    }

    public function observations(): BelongsToMany
    {
        $relation = $this->belongsToMany(FieldObservation::class, 'field_evidence_claim_observations');
        if (config('database.default') === 'pgsql') {
            $relation->addSelect(['field_observations.*', DB::raw('ST_AsText(field_observations.location) AS location_wkt')]);
        }

        return $relation->withTimestamps();
    }

    public function supportingSources(): BelongsToMany
    {
        return $this->belongsToMany(ProvenanceSource::class, 'field_evidence_claim_sources')
            ->withTimestamps();
    }

    public function provenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }
}
