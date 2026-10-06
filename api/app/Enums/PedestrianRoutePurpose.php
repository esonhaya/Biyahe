<?php

namespace App\Enums;

enum PedestrianRoutePurpose: string
{
    case ACCESS_WALK = 'ACCESS_WALK';
    case EGRESS_WALK = 'EGRESS_WALK';
    case TRANSFER_WALK = 'TRANSFER_WALK';
}
