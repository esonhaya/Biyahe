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
        public readonly string $reasonCode = 'UNKNOWN_DATA',
        public readonly ?array $route = null,
        public readonly ?array $option = null,
        public readonly ?array $oppositeOption = null,
        public readonly array $provenance = [],
        public readonly array $warnings = [],
        public readonly array $disruptions = [],
        public readonly string $operationalImpact = 'INFORMATIONAL',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'reason_code' => $this->reasonCode,
            'route' => $this->route,
            'option' => $this->option,
            'opposite_direction' => $this->oppositeOption,
            'trust' => $this->option['trust'] ?? $this->oppositeOption['trust'] ?? null,
            'warnings' => $this->warnings,
            'operational_impact' => $this->operationalImpact,
            'disruptions' => $this->disruptions,
            'provenance' => $this->provenance,
        ];
    }

    /** @param list<array<string, mixed>> $disruptions */
    public function withOperationalImpacts(array $disruptions): self
    {
        $levels = array_map(static fn (array $item): string => (string) ($item['impact_level'] ?? 'INFORMATIONAL'), $disruptions);
        $impact = in_array('SERVICE_UNAVAILABLE', $levels, true)
            ? 'SERVICE_UNAVAILABLE'
            : (in_array('AFFECTED', $levels, true)
                ? 'AFFECTED'
                : (in_array('POSSIBLY_AFFECTED', $levels, true) ? 'POSSIBLY_AFFECTED' : 'INFORMATIONAL'));
        $status = $this->status;
        $message = $this->message;
        $reasonCode = $this->reasonCode;
        if ($this->option !== null || $this->oppositeOption !== null) {
            if ($impact === 'SERVICE_UNAVAILABLE') {
                $status = 'TEMPORARILY_UNAVAILABLE';
                $reasonCode = 'ACTIVE_HARD_CLOSURE';
                $message = 'This route or access point is temporarily affected by a reviewed operational advisory.';
            } elseif ($impact === 'POSSIBLY_AFFECTED') {
                $status = 'UNCERTAIN_DUE_TO_REPORT';
                $reasonCode = 'COMMUNITY_SIGNAL_UNCERTAIN';
                $message = 'This route may be affected by a commuter report that is still operationally uncertain.';
            } elseif ($impact === 'AFFECTED' && $this->status === 'YES') {
                $status = 'YES_BUT_AFFECTED';
                $reasonCode = 'ACTIVE_ADVISORY';
                $message = 'Yes, but an active operational advisory may affect this route.';
            }
        }

        return new self(
            status: $status,
            message: $message,
            reasonCode: $reasonCode,
            route: $this->route,
            option: $this->option,
            oppositeOption: $this->oppositeOption,
            provenance: $this->provenance,
            warnings: array_values(array_unique(array_filter(array_merge($this->warnings, array_map(static fn (array $item): string => (string) ($item['warning'] ?? ''), $disruptions))))),
            disruptions: $disruptions,
            operationalImpact: $impact,
        );
    }
}
