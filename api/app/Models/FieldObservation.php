<?php

namespace App\Models;

use App\Enums\FieldLocationMethod;
use App\Enums\FieldLocationPrecision;
use App\Enums\FieldObservationStatus;
use App\Enums\FieldObservationType;
use App\Support\Geo;
use Database\Factories\FieldObservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FieldObservation extends Model
{
    /** @use HasFactory<FieldObservationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'import_hash',
        'location',
        'reported_accuracy_m',
        'location_method',
        'location_precision',
        'observed_fact',
        'notes',
        'supporting_references',
        'fare_amount',
        'fare_currency',
        'passenger_class',
        'observation_window_minutes',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'observed_at' => 'datetime',
            'reported_accuracy_m' => 'float',
            'observed_fact' => 'array',
            'fare_amount' => 'decimal:2',
            'supporting_references' => 'array',
            'review_status' => FieldObservationStatus::class,
            'observation_type' => FieldObservationType::class,
            'location_method' => FieldLocationMethod::class,
            'location_precision' => FieldLocationPrecision::class,
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

    public function surveySession(): BelongsTo
    {
        return $this->belongsTo(TransportSurveySession::class, 'survey_session_id');
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function claims(): BelongsToMany
    {
        return $this->belongsToMany(FieldEvidenceClaim::class, 'field_evidence_claim_observations')
            ->withTimestamps();
    }
}
