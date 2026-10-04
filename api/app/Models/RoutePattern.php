<?php

namespace App\Models;

use App\Enums\VerificationState;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutePattern extends Model
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

    public function setGeometryAttribute(mixed $value): void
    {
        $this->attributes['geometry'] = is_array($value) ? Geo::multiLineStringWkt($value) : $value;
    }

    public function getGeometryGeoJsonAttribute(): ?array
    {
        return Geo::geometryGeoJson($this->attributes['geometry'] ?? null);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function provenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class);
    }

    public function patternStops(): HasMany
    {
        return $this->hasMany(RoutePatternStop::class)->orderBy('sequence');
    }

    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(Stop::class, 'route_pattern_stops')
            ->withPivot(['sequence', 'boarding_allowed', 'alighting_allowed', 'instruction', 'service_metadata'])
            ->orderBy('route_pattern_stops.sequence');
    }

    public function fares(): HasMany
    {
        return $this->hasMany(Fare::class);
    }
}
