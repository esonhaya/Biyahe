<?php

namespace App\Models;

use App\Enums\VerificationState;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Place extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'verification_state' => VerificationState::class,
        ];
    }

    public function setLocationAttribute(mixed $value): void
    {
        if (is_array($value) && isset($value['lat'], $value['lng'])) {
            $value = Geo::pointWkt((float) $value['lat'], (float) $value['lng']);
        }

        $this->attributes['location'] = $value;
    }

    public function getLocationPointAttribute(): ?array
    {
        return Geo::parsePoint($this->attributes['location_wkt'] ?? $this->attributes['location'] ?? null);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(PlaceAlias::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(Stop::class, 'nearby_place_id');
    }

    public function provenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class);
    }
}
