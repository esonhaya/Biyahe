<?php

namespace App\Services\Routing;

use RuntimeException;

final class OfflineWalkingPreviewException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
