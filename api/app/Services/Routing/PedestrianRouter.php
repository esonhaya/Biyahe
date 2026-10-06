<?php

namespace App\Services\Routing;

interface PedestrianRouter
{
    public function route(PedestrianRouteRequest $request): PedestrianRouteResult;

    /** @return array{candidates: int, routes_computed: int, routes_available: int, route_failures: int, cache_hits: int, no_route_results: int, routing_time_milliseconds: float, provider_status: string} */
    public function metrics(): array;
}
