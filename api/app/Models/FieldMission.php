<?php

namespace App\Models;

use App\Enums\FieldMissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldMission extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'claim_checklist' => 'array',
            'route_scope' => 'array',
            'status' => FieldMissionStatus::class,
            'is_synthetic' => 'boolean',
        ];
    }

    public function claims(): HasMany
    {
        return $this->hasMany(FieldMissionClaim::class)->orderByDesc('priority_score')->orderBy('id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TransportSurveySession::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
