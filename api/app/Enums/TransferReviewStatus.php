<?php

namespace App\Enums;

enum TransferReviewStatus: string
{
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case SUPPORTED = 'SUPPORTED';
    case REJECTED = 'REJECTED';
    case NEEDS_RECHECK = 'NEEDS_RECHECK';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
