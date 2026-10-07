<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisruptionReview extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function disruption(): BelongsTo
    {
        return $this->belongsTo(Disruption::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
