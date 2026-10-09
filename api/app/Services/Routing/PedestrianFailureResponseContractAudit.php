<?php

namespace App\Services\Routing;

/** Checks the existing backend/client fallback contract without contacting a database. */
final class PedestrianFailureResponseContractAudit
{
    /** @return array<string, mixed> */
    public function audit(string $repositoryRoot): array
    {
        $apiRouter = $this->read($repositoryRoot.'/api/app/Services/Routing/LocalPedestrianGraphRouter.php');
        $apiResult = $this->read($repositoryRoot.'/api/app/Services/Routing/PedestrianRouteResult.php');
        $client = $this->read($repositoryRoot.'/web/src/lib/commuteInstructions.ts');
        $harness = $this->read($repositoryRoot.'/web/scripts/commute-card-harness.mjs');
        $checks = [
            'backend_graph_unavailable_is_not_a_route' => str_contains($apiRouter, 'No local OpenStreetMap pedestrian graph is installed; distance is a straight-line estimate, not a walking route.')
                && str_contains($apiResult, 'public ?int $estimatedDurationSeconds = null')
                && str_contains($apiResult, 'public ?array $geometry = null'),
            'client_labels_unavailable_distance_as_estimate' => str_contains($client, 'is a straight-line estimate; walking access could not be confirmed')
                && str_contains($client, 'Walking path unavailable — distance shown is an estimate.'),
            'client_omits_geometry_for_estimate' => str_contains($client, 'function walkingGeometry')
                && str_contains($client, 'return WALKING_NETWORK_TYPES.has(leg.distance_type ?? "") ? leg.geometry : null;'),
            'harness_asserts_null_fallback_duration_and_geometry' => str_contains($harness, 'assert.equal(fallbackInstruction?.estimatedDurationSeconds, null);')
                && str_contains($harness, 'assert.equal(fallbackInstruction?.geometry, null);')
                && str_contains($harness, 'assert.equal(fallbackInstruction?.distanceType, "STRAIGHT_LINE_ESTIMATE");'),
        ];
        $complete = ! in_array(false, $checks, true);

        return [
            'honest_unavailable_state' => $complete,
            'status' => $complete ? 'PASS' : 'WARN',
            'postgresql_contacted' => false,
            'checks' => $checks,
            'sources' => [
                'api/app/Services/Routing/LocalPedestrianGraphRouter.php',
                'api/app/Services/Routing/PedestrianRouteResult.php',
                'web/src/lib/commuteInstructions.ts',
                'web/scripts/commute-card-harness.mjs',
            ],
        ];
    }

    private function read(string $path): string
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return is_string($contents) ? $contents : '';
    }
}
