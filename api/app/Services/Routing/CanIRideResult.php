<?php

namespace App\Services\Routing;

final class CanIRideResult
{
    /**
     * @param  array<string, mixed>|null  $route
     * @param  array<string, mixed>|null  $option
     * @param  array<string, mixed>|null  $oppositeOption
     * @param  list<array<string, mixed>>  $provenance
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?array $route = null,
        public readonly ?array $option = null,
        public readonly ?array $oppositeOption = null,
        public readonly array $provenance = [],
        public readonly array $warnings = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'route' => $this->route,
            'option' => $this->option,
            'opposite_direction' => $this->oppositeOption,
            'trust' => $this->option['trust'] ?? $this->oppositeOption['trust'] ?? null,
            'warnings' => $this->warnings,
            'provenance' => $this->provenance,
        ];
    }
}
