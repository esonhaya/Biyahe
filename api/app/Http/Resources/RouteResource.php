<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'city' => new CityResource($this->whenLoaded('city')),
            'operator' => $this->whenLoaded('operator', fn () => [
                'id' => $this->operator->id,
                'name' => $this->operator->name,
            ]),
            'vehicle_type' => $this->whenLoaded('vehicleType', fn () => [
                'code' => $this->vehicleType->code,
                'name' => $this->vehicleType->name,
            ]),
            'patterns' => RoutePatternResource::collection($this->whenLoaded('patterns')),
            'verification' => [
                'state' => $this->verification_state?->value ?? $this->verification_state,
                'is_demo' => (bool) $this->is_demo,
            ],
            'provenance' => new ProvenanceSourceResource($this->whenLoaded('provenanceSource')),
        ];
    }
}
