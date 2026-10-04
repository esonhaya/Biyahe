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
            'accessed_at' => $this->accessed_at?->toDateString() ?? $this->accessed_at,
            'checked_at' => $this->checked_at?->toDateString() ?? $this->checked_at,
            'effective_from' => $this->effective_from?->toDateString() ?? $this->effective_from,
            'effective_until' => $this->effective_until?->toDateString() ?? $this->effective_until,
            'license' => $this->license,
            'verification_method' => $this->verification_method,
            'verified_by' => $this->verified_by,
            'notes' => $this->notes,
        ];
    }
}
