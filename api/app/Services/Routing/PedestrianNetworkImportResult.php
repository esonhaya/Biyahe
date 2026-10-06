<?php

namespace App\Services\Routing;

final readonly class PedestrianNetworkImportResult
{
    public function __construct(
        public string $stableId,
        public int $nodes,
        public int $edges,
        public bool $alreadyImported,
    ) {}
}
