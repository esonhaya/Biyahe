<?php

namespace App\Http\Resources;

use App\Support\Geo;
use App\Support\Trust;
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
            'verification' => Trust::status($this->resource),
            'provenance' => new ProvenanceSourceResource($this->whenLoaded('provenanceSource')),
        ];
    }
}
