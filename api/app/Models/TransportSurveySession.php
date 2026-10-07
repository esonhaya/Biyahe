<?php

namespace App\Models;

use App\Enums\SurveySessionStatus;
use Database\Factories\TransportSurveySessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class TransportSurveySession extends Model
{
    /** @use HasFactory<TransportSurveySessionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'observer_user_id',
        'observer_reference',
        'created_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'status' => SurveySessionStatus::class,
            'is_synthetic' => 'boolean',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(FieldMission::class, 'field_mission_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function routePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class);
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observer_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function observations(): HasMany
    {
        $relation = $this->hasMany(FieldObservation::class, 'survey_session_id');
        if (config('database.default') === 'pgsql') {
            $relation->addSelect(['field_observations.*', DB::raw('ST_AsText(field_observations.location) AS location_wkt')]);
        }

        return $relation->orderBy('sequence');
    }

    public function tracePoints(): HasMany
    {
        $relation = $this->hasMany(SurveyTracePoint::class, 'survey_session_id');
        if (config('database.default') === 'pgsql') {
            $relation->addSelect(['survey_trace_points.*', DB::raw('ST_AsText(survey_trace_points.location) AS location_wkt')]);
        }

        return $relation->orderBy('sequence');
    }
}
