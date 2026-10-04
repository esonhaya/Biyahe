<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutePatternStop extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'boarding_allowed' => 'boolean',
            'alighting_allowed' => 'boolean',
            'service_metadata' => 'array',
        ];
    }

    public function routePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }
}
