<?php

namespace App\Enums;

enum JourneyLegType: string
{
    case WALK_ACCESS = 'WALK_ACCESS';
    case RIDE = 'RIDE';
    case TRANSFER_WALK = 'TRANSFER_WALK';
    case WALK_EGRESS = 'WALK_EGRESS';
}
