<?php

namespace App\Models;

use App\Enums\VerificationState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class City extends Model
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

    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(Stop::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    public function operators(): HasManyThrough
    {
        return $this->hasManyThrough(Operator::class, Route::class, 'city_id', 'id', 'id', 'operator_id');
    }
}
