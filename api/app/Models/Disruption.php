<?php

namespace App\Models;

use App\Enums\DisruptionCategory;
use App\Enums\DisruptionSeverity;
use App\Enums\DisruptionSourceClass;
use App\Enums\DisruptionStatus;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Disruption extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => DisruptionCategory::class,
            'severity' => DisruptionSeverity::class,
            'status' => DisruptionStatus::class,
            'source_class' => DisruptionSourceClass::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'is_synthetic' => 'boolean',
        ];
    }

    public function setGeometryAttribute(mixed $value): void
    {
        if (is_array($value)) {
            $value = Geo::geometryWkt($value);
        }

        $this->attributes['geometry'] = $value;
    }

    public function getGeometryGeoJsonAttribute(): ?array
    {
        return Geo::geometryGeoJson($this->attributes['geometry_geojson'] ?? $this->attributes['geometry'] ?? null);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function provenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function routes(): BelongsToMany
    {
        return $this->belongsToMany(Route::class, 'disruption_routes');
    }

    public function patterns(): BelongsToMany
    {
        return $this->belongsToMany(RoutePattern::class, 'disruption_route_patterns');
    }

    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(Stop::class, 'disruption_stops');
    }

    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class, 'disruption_places');
    }

    public function transfers(): BelongsToMany
    {
        return $this->belongsToMany(TransferRelationship::class, 'disruption_transfers');
    }

    public function commuterReports(): HasMany
    {
        return $this->hasMany(CommuterReport::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DisruptionReview::class);
    }
}
