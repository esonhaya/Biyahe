<?php

namespace App\Models;

use App\Enums\VerificationState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
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

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function patterns(): HasMany
    {
        return $this->hasMany(RoutePattern::class);
    }

    public function fares(): HasMany
    {
        return $this->hasMany(Fare::class);
    }

    public function provenanceSource(): BelongsTo
    {
        return $this->belongsTo(ProvenanceSource::class);
    }

    public function disruptions(): BelongsToMany
    {
        return $this->belongsToMany(Disruption::class, 'disruption_routes');
    }
}
