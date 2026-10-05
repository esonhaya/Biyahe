<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProvenanceSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->source_type,
            'title' => $this->title,
            'publisher' => $this->publisher,
            'url' => $this->url,
            'stable_id' => $this->stable_id,
            'accessed_at' => $this->accessed_at?->toDateString() ?? $this->accessed_at,
            'published_at' => $this->published_at?->toDateString() ?? $this->published_at,
            'observed_at' => $this->observed_at?->toDateString() ?? $this->observed_at,
            'checked_at' => $this->checked_at?->toDateString() ?? $this->checked_at,
            'effective_from' => $this->effective_from?->toDateString() ?? $this->effective_from,
            'effective_until' => $this->effective_until?->toDateString() ?? $this->effective_until,
            'evidence_scope' => $this->evidence_scope,
            'conflict_status' => $this->conflict_status?->value ?? $this->conflict_status,
            'conflict_group' => $this->conflict_group,
            'superseded_by_id' => $this->superseded_by_id,
            'license' => $this->license,
            'verification_method' => $this->verification_method,
            'verified_by' => $this->verified_by,
            'notes' => $this->notes,
        ];
    }
}
