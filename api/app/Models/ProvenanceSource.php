<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProvenanceSource extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accessed_at' => 'date',
            'checked_at' => 'date',
            'effective_from' => 'date',
            'effective_until' => 'date',
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
}
