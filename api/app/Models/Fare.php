<?php

namespace App\Models;

use App\Enums\VerificationState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fare extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'min_distance_km' => 'decimal:2',
            'max_distance_km' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_demo' => 'boolean',
            'verification_state' => VerificationState::class,
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function routePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function originStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'origin_stop_id');
    }

    public function destinationStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'destination_stop_id');
    }

    public function provenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class);
    }
}
