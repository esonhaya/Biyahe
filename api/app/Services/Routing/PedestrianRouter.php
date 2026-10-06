<?php

namespace App\Services\Routing;

interface PedestrianRouter
{
    public function route(PedestrianRouteRequest $request): PedestrianRouteResult;

    /** @return array{candidates: int, routes_computed: int, cache_hits: int, routing_time_milliseconds: float, provider_status: string} */
    public function metrics(): array;
}
