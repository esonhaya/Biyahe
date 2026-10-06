<?php

namespace App\Enums;

enum SurveySessionStatus: string
{
    case PLANNED = 'PLANNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case ABORTED = 'ABORTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case REVIEWED = 'REVIEWED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
