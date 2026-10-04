<?php

namespace App\Http\Resources;

use App\Support\Geo;
use App\Support\Trust;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutePatternResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $geometry = Geo::geometryGeoJson($this->geometry_geo_json ?? $this->geometry_wkt ?? $this->getRawOriginal('geometry'));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'direction' => $this->direction,
            'service_notes' => $this->service_notes,
            'geometry' => $geometry,
            'route' => $this->whenLoaded('route', fn () => [
                'id' => $this->route->id,
                'code' => $this->route->code,
                'name' => $this->route->name,
            ]),
            'stops' => RoutePatternStopResource::collection($this->whenLoaded('patternStops')),
            'verification' => Trust::status($this->resource),
            'provenance' => new ProvenanceSourceResource($this->whenLoaded('provenanceSource')),
        ];
    }
}
