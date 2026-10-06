<?php

namespace App\Models;

use App\Enums\FieldLocationMethod;
use App\Enums\FieldLocationPrecision;
use App\Support\Geo;
use Database\Factories\SurveyTracePointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyTracePoint extends Model
{
    /** @use HasFactory<SurveyTracePointFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['import_hash', 'location', 'reported_accuracy_m', 'location_method', 'location_precision'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'observed_at' => 'datetime',
            'reported_accuracy_m' => 'float',
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
}
