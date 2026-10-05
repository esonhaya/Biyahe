<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\DB;

final class TransferReviewFingerprint
{
    public function forPair(int $firstStopId, int $secondStopId): string
    {
        $stopIds = [$firstStopId, $secondStopId];
        sort($stopIds);
        $connection = DB::connection();
        $location = $connection->getDriverName() === 'pgsql'
            ? 'ST_AsText(stops.location) AS location_text'
            : 'stops.location AS location_text';

        $stops = DB::table('stops')
            ->whereIn('stops.id', $stopIds)
            ->orderBy('stops.id')
            ->get([
                'stops.id',
                'stops.city_id',
                'stops.name',
                'stops.code',
                'stops.is_active',
                'stops.is_demo',
                'stops.is_boarding_point',
                'stops.verification_state',
                'stops.boarding_precision',
                'stops.boarding_verification_state',
                'stops.provenance_source_id',
                'stops.boarding_provenance_source_id',
                'stops.updated_at',
                DB::raw($location),
            ])
            ->map(fn (object $stop): array => (array) $stop)
            ->all();

        $associations = DB::table('route_pattern_stops as rps')
            ->join('route_patterns as patterns', 'patterns.id', '=', 'rps.route_pattern_id')
            ->join('routes', 'routes.id', '=', 'patterns.route_id')
            ->whereIn('rps.stop_id', $stopIds)
            ->orderBy('rps.stop_id')
            ->orderBy('patterns.id')
            ->get([
                'rps.id',
                'rps.stop_id',
                'rps.route_pattern_id',
                'rps.sequence',
                'rps.boarding_allowed',
                'rps.alighting_allowed',
                'rps.verification_state as association_verification_state',
                'rps.service_verification_state',
                'rps.sequence_verification_state',
                'rps.boarding_verification_state',
                'rps.alighting_verification_state',
                'rps.provenance_source_id as association_provenance_source_id',
                'rps.service_provenance_source_id',
                'rps.sequence_provenance_source_id',
                'rps.boarding_provenance_source_id',
                'rps.alighting_provenance_source_id',
                'rps.updated_at as association_updated_at',
                'patterns.route_id',
                'patterns.name as pattern_name',
                'patterns.direction',
                'patterns.is_active as pattern_is_active',
                'patterns.is_demo as pattern_is_demo',
                'patterns.verification_state as pattern_verification_state',
                'patterns.provenance_source_id as pattern_provenance_source_id',
                'patterns.updated_at as pattern_updated_at',
                'routes.code as route_code',
                'routes.is_active as route_is_active',
                'routes.is_demo as route_is_demo',
                'routes.verification_state as route_verification_state',
                'routes.provenance_source_id as route_provenance_source_id',
                'routes.updated_at as route_updated_at',
            ])
            ->map(fn (object $association): array => (array) $association)
            ->all();

        $sourceIds = collect($stops)
            ->flatMap(fn (array $stop): array => [$stop['provenance_source_id'], $stop['boarding_provenance_source_id']])
            ->merge(collect($associations)->flatMap(fn (array $association): array => [
                $association['association_provenance_source_id'],
                $association['service_provenance_source_id'],
                $association['sequence_provenance_source_id'],
                $association['boarding_provenance_source_id'],
                $association['alighting_provenance_source_id'],
                $association['pattern_provenance_source_id'],
                $association['route_provenance_source_id'],
            ]))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $sources = $sourceIds->isEmpty()
            ? []
            : DB::table('provenance_sources')
                ->whereIn('id', $sourceIds)
                ->orderBy('id')
                ->get([
                    'id',
                    'source_type',
                    'checked_at',
                    'published_at',
                    'observed_at',
                    'effective_from',
                    'effective_until',
                    'evidence_scope',
                    'conflict_status',
                    'superseded_by_id',
                    'updated_at',
                ])
                ->map(fn (object $source): array => (array) $source)
                ->all();

        return hash('sha256', json_encode([
            'stops' => $stops,
            'associations' => $associations,
            'sources' => $sources,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
