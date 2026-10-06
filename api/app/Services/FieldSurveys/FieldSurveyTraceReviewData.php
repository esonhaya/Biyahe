<?php

namespace App\Services\FieldSurveys;

use App\Models\RoutePattern;
use App\Models\TransportSurveySession;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

final class FieldSurveyTraceReviewData
{
    /** @return array<string, mixed> */
    public function forSession(TransportSurveySession $session): array
    {
        $observations = $session->observations->map(fn ($observation): array => [
            'sequence' => $observation->sequence,
            'type' => $observation->observation_type->value,
            'observed_at' => $observation->observed_at?->toIso8601String(),
            'point' => $observation->location_point,
            'accuracy_m' => $observation->reported_accuracy_m,
            'precision' => $observation->location_precision->value,
            'fact' => $observation->observed_fact,
        ])->all();
        $tracePoints = $session->tracePoints->map(fn ($point): array => [
            'sequence' => $point->sequence,
            'observed_at' => $point->observed_at?->toIso8601String(),
            'point' => $point->location_point,
            'accuracy_m' => $point->reported_accuracy_m,
            'precision' => $point->location_precision->value,
        ])->all();

        return [
            'route_lines' => $this->routeLines($session->route_pattern_id),
            'mapped_stops' => $this->mappedStops($session->route_pattern_id),
            'observations' => $observations,
            'trace_points' => $tracePoints,
            'potential_jumps' => $this->potentialJumps($tracePoints),
        ];
    }

    /** @return list<list<array{lat: float, lng: float}>> */
    private function routeLines(?int $patternId): array
    {
        if ($patternId === null) {
            return [];
        }

        $query = RoutePattern::query()->whereKey($patternId);
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->addSelect(['route_patterns.*', DB::raw('ST_AsText(route_patterns.geometry) AS geometry_wkt')]);
        }
        $pattern = $query->first();
        $geometry = Geo::geometryGeoJson($pattern?->geometry_wkt ?? $pattern?->getRawOriginal('geometry'));
        if ($geometry === null || ! in_array($geometry['type'] ?? null, ['LineString', 'MultiLineString'], true)) {
            return [];
        }
        $lines = ($geometry['type'] ?? null) === 'LineString' ? [$geometry['coordinates']] : $geometry['coordinates'];

        return array_values(array_filter(array_map(static function (mixed $line): array {
            if (! is_array($line)) {
                return [];
            }

            return array_values(array_filter(array_map(static function (mixed $coordinate): ?array {
                if (! is_array($coordinate) || ! isset($coordinate[0], $coordinate[1])
                    || ! is_numeric($coordinate[0]) || ! is_numeric($coordinate[1])) {
                    return null;
                }

                return ['lat' => (float) $coordinate[1], 'lng' => (float) $coordinate[0]];
            }, $line)));
        }, $lines), static fn (array $line): bool => $line !== []));
    }

    /** @return list<array<string, mixed>> */
    private function mappedStops(?int $patternId): array
    {
        if ($patternId === null) {
            return [];
        }

        $query = DB::table('route_pattern_stops as associations')
            ->join('stops', 'stops.id', '=', 'associations.stop_id')
            ->where('associations.route_pattern_id', $patternId)
            ->orderBy('associations.sequence')
            ->select([
                'associations.sequence',
                'associations.service_verification_state',
                'associations.sequence_verification_state',
                'associations.boarding_verification_state',
                'stops.stable_id',
                'stops.name',
            ]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->selectRaw('ST_AsText(stops.location::geometry) AS location_wkt');
        } else {
            $query->addSelect('stops.location as location_wkt');
        }

        return $query->get()->map(fn (object $stop): array => [
            'sequence' => (int) $stop->sequence,
            'stable_id' => $stop->stable_id,
            'name' => $stop->name,
            'point' => Geo::parsePoint($stop->location_wkt),
            'service_state' => $stop->service_verification_state,
            'sequence_state' => $stop->sequence_verification_state,
            'boarding_state' => $stop->boarding_verification_state,
        ])->all();
    }

    /** @param list<array<string, mixed>> $tracePoints @return list<array<string, mixed>> */
    private function potentialJumps(array $tracePoints): array
    {
        $jumps = [];
        $previous = null;
        foreach ($tracePoints as $point) {
            if ($previous !== null && is_array($previous['point']) && is_array($point['point'])) {
                $distance = Geo::distanceMeters(
                    $previous['point']['lat'],
                    $previous['point']['lng'],
                    $point['point']['lat'],
                    $point['point']['lng'],
                );
                $elapsedSeconds = $previous['observed_at'] !== null && $point['observed_at'] !== null
                    ? max(0, (strtotime($point['observed_at']) ?: 0) - (strtotime($previous['observed_at']) ?: 0))
                    : null;
                $accuracyAllowance = (float) ($previous['accuracy_m'] ?? 0) + (float) ($point['accuracy_m'] ?? 0);
                $possibleJump = $elapsedSeconds === null
                    ? $distance > 5000
                    : $distance > (($elapsedSeconds * 50) + $accuracyAllowance);
                if ($possibleJump) {
                    $jumps[] = [
                        'from_sequence' => $previous['sequence'],
                        'to_sequence' => $point['sequence'],
                        'from_point' => $previous['point'],
                        'to_point' => $point['point'],
                        'distance_m' => round($distance, 1),
                        'elapsed_seconds' => $elapsedSeconds,
                    ];
                }
            }
            $previous = $point;
        }

        return $jumps;
    }
}
