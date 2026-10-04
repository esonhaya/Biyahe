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
        ];
    }
}
