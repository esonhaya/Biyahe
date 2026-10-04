<?php

namespace App\Http\Resources;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $location = $this->location_wkt ?? $this->getRawOriginal('location');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'location' => Geo::parsePoint($location),
            'city' => new CityResource($this->whenLoaded('city')),
            'aliases' => PlaceAliasResource::collection($this->whenLoaded('aliases')),
            'verification' => [
                'state' => $this->verification_state?->value ?? $this->verification_state,
                'is_demo' => (bool) $this->is_demo,
            ],
            'provenance' => new ProvenanceSourceResource($this->whenLoaded('provenanceSource')),
        ];
    }
}
