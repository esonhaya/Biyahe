<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutePatternStopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sequence' => (int) $this->sequence,
            'boarding_allowed' => (bool) $this->boarding_allowed,
            'alighting_allowed' => (bool) $this->alighting_allowed,
            'instruction' => $this->instruction,
            'service_metadata' => $this->service_metadata,
            'stop' => new StopResource($this->whenLoaded('stop')),
            'provenance' => new ProvenanceSourceResource($this->whenLoaded('provenanceSource')),
        ];
    }
}
