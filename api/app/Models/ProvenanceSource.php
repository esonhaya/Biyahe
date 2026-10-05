<?php

namespace App\Models;

use App\Enums\EvidenceConflictStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProvenanceSource extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accessed_at' => 'date',
            'published_at' => 'date',
            'observed_at' => 'date',
            'checked_at' => 'date',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'evidence_scope' => 'array',
            'conflict_status' => EvidenceConflictStatus::class,
        ];
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(Stop::class);
    }

    public function operators(): HasMany
    {
        return $this->hasMany(Operator::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    public function patterns(): HasMany
    {
        return $this->hasMany(RoutePattern::class);
    }

    public function fares(): HasMany
    {
        return $this->hasMany(Fare::class);
    }

    public function transferRelationships(): HasMany
    {
        return $this->hasMany(TransferRelationship::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }
}
