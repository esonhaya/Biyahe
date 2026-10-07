<?php

namespace App\Models;

use App\Enums\CommuterReportCategory;
use App\Enums\CommuterReportStatus;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommuterReport extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => CommuterReportCategory::class,
            'status' => CommuterReportStatus::class,
            'observed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'location_accuracy_m' => 'float',
            'is_synthetic' => 'boolean',
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
        return Geo::parsePoint($this->attributes['location_geojson'] ?? $this->attributes['location_wkt'] ?? $this->attributes['location'] ?? null);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function routePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function transferRelationship(): BelongsTo
    {
        return $this->belongsTo(TransferRelationship::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    public function disruption(): BelongsTo
    {
        return $this->belongsTo(Disruption::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CommuterReportReview::class);
    }
}
