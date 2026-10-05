<?php

namespace App\Services\Routing;

final class JourneyRanker
{
    /** @param list<Journey> $journeys @return list<Journey> */
    public function rank(array $journeys): array
    {
        usort($journeys, function (Journey $left, Journey $right): int {
            return ($left->trustState->rank() <=> $right->trustState->rank())
                ?: ($left->transferCount <=> $right->transferCount)
                ?: ($left->walkingDistanceMeters <=> $right->walkingDistanceMeters)
                ?: ($left->rideStopCount <=> $right->rideStopCount)
                ?: strcmp($left->id, $right->id);
        });

        return array_slice($journeys, 0, (int) config('biyahe.routing.max_results', 10));
    }
}
