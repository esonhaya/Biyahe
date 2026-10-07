<?php

namespace App\Enums;

enum CommuterReportStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case ACCEPTED_AS_SIGNAL = 'ACCEPTED_AS_SIGNAL';
    case REJECTED = 'REJECTED';
    case DUPLICATE = 'DUPLICATE';
    case EXPIRED = 'EXPIRED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
