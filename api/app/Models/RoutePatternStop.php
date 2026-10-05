<?php

namespace App\Models;

use App\Enums\VerificationState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutePatternStop extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'boarding_allowed' => 'boolean',
            'alighting_allowed' => 'boolean',
            'service_metadata' => 'array',
            'verification_state' => VerificationState::class,
            'service_verification_state' => VerificationState::class,
            'sequence_verification_state' => VerificationState::class,
            'boarding_verification_state' => VerificationState::class,
            'alighting_verification_state' => VerificationState::class,
        ];
    }

    public function routePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }

    public function provenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class);
    }

    public function serviceProvenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class, 'service_provenance_source_id');
    }

    public function sequenceProvenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class, 'sequence_provenance_source_id');
    }

    public function boardingProvenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class, 'boarding_provenance_source_id');
    }

    public function alightingProvenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class, 'alighting_provenance_source_id');
    }
}
