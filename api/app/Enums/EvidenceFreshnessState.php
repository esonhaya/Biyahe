<?php

namespace App\Enums;

enum EvidenceFreshnessState: string
{
    case CURRENT = 'CURRENT';
    case AGING = 'AGING';
    case STALE = 'STALE';
    case UNKNOWN_FRESHNESS = 'UNKNOWN_FRESHNESS';
}
