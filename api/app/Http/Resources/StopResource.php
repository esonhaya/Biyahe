<?php

namespace App\Http\Resources;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $location = $this->location_wkt ?? $this->getRawOriginal('location');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'category' => $this->category,
            'location' => Geo::parsePoint($location),
            'is_boarding_point' => (bool) $this->is_boarding_point,
            'boarding_notes' => $this->boarding_notes,
            'city' => new CityResource($this->whenLoaded('city')),
            'nearby_place' => new PlaceResource($this->whenLoaded('nearbyPlace')),
            'verification' => [
                'state' => $this->verification_state?->value ?? $this->verification_state,
                'is_demo' => (bool) $this->is_demo,
            ],
            'provenance' => new ProvenanceSourceResource($this->whenLoaded('provenanceSource')),
            'distance_meters' => isset($this->distance_meters) ? round((float) $this->distance_meters, 2) : null,
        ];
    }
}
