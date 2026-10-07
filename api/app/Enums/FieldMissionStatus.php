<?php

namespace App\Enums;

enum FieldMissionStatus: string
{
    case PLANNED = 'PLANNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case PARTIALLY_ACCEPTED = 'PARTIALLY_ACCEPTED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
