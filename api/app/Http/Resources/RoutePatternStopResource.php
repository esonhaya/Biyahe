<?php

namespace App\Http\Resources;

use App\Support\Trust;
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
            'verification' => Trust::status($this->resource),
            'evidence' => [
                'service' => ['state' => Trust::effectiveFactState($this->resource, 'service'), 'provenance' => new ProvenanceSourceResource($this->whenLoaded('serviceProvenanceSource'))],
                'sequence' => ['state' => Trust::effectiveFactState($this->resource, 'sequence'), 'provenance' => new ProvenanceSourceResource($this->whenLoaded('sequenceProvenanceSource'))],
                'boarding' => ['state' => Trust::effectiveFactState($this->resource, 'boarding'), 'provenance' => new ProvenanceSourceResource($this->whenLoaded('boardingProvenanceSource'))],
                'alighting' => ['state' => Trust::effectiveFactState($this->resource, 'alighting'), 'provenance' => new ProvenanceSourceResource($this->whenLoaded('alightingProvenanceSource'))],
            ],
            'instruction' => $this->instruction,
            'service_metadata' => $this->service_metadata,
            'stop' => new StopResource($this->whenLoaded('stop')),
            'provenance' => new ProvenanceSourceResource($this->whenLoaded('provenanceSource')),
        ];
    }
}
