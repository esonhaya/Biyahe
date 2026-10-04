<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'country_code' => $this->country_code,
            'is_active' => (bool) $this->is_active,
            'verification_state' => $this->verification_state?->value ?? $this->verification_state,
            'is_demo' => (bool) $this->is_demo,
        ];
    }
}
