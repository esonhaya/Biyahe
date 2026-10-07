<?php

namespace App\Services\Routing;

final class JourneyRanker
{
    /** @param list<Journey> $journeys @return list<Journey> */
    public function rank(array $journeys): array
    {
        usort($journeys, function (Journey $left, Journey $right): int {
            return ($left->trustState->rank() <=> $right->trustState->rank())
                ?: ($left->operationalImpactRank() <=> $right->operationalImpactRank())
                ?: ($left->transferCount <=> $right->transferCount)
                ?: ($left->walkingDistanceMeters <=> $right->walkingDistanceMeters)
                ?: ($left->rideStopCount <=> $right->rideStopCount)
                ?: strcmp($left->id, $right->id);
        });

        $journeys = array_slice($journeys, 0, (int) config('biyahe.routing.max_results', 10));
        $best = $journeys[0] ?? null;

        return array_map(
            fn (Journey $journey): Journey => $journey->withRankingReasons($this->reasonsFor($journey, $best, $journeys)),
            $journeys,
        );
    }

    /** @param list<Journey> $journeys @return list<string> */
    private function reasonsFor(Journey $journey, ?Journey $best, array $journeys): array
    {
        if ($best === null) {
            return [];
        }

        $reasons = [];
        $minimumTrustRank = min(array_map(
            static fn (Journey $candidate): int => $candidate->trustState->rank(),
            $journeys,
        ));
        $minimumWalking = min(array_map(
            static fn (Journey $candidate): float => $candidate->walkingDistanceMeters,
            $journeys,
        ));
        $minimumTransfers = min(array_map(
            static fn (Journey $candidate): int => $candidate->transferCount,
            $journeys,
        ));

        if ($journey->id === $best->id) {
            if ($journey->trustState->rank() === $minimumTrustRank) {
                $reasons[] = $journey->trustState->rank() === 0
                    ? 'Best verified information'
                    : 'Best available information';
            }
            if ($journey->transferCount === 0) {
                $reasons[] = 'Direct ride';
            } elseif ($journey->transferCount === $minimumTransfers) {
                $reasons[] = 'Fewer transfers';
            }
            if (abs($journey->walkingDistanceMeters - $minimumWalking) < 0.01) {
                $reasons[] = 'Shortest walking estimate';
            }

            return $reasons === [] ? ['Best available combination'] : $reasons;
        }

        if ($journey->transferCount > $best->transferCount) {
            $reasons[] = 'More transfers';
        }
        if ($journey->walkingDistanceMeters > $best->walkingDistanceMeters) {
            $reasons[] = 'Longer walking estimate';
        }
        if ($journey->trustState->rank() > $best->trustState->rank()) {
            $reasons[] = 'Less verified information';
        }
        if ($journey->operationalImpactRank() > $best->operationalImpactRank()) {
            $reasons[] = 'More exposed to an active commute advisory';
        }

        return $reasons === [] ? ['Alternative boarding point or route'] : $reasons;
    }
}
