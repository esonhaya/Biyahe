<?php

namespace App\Enums;

enum FieldObservationStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case ACCEPTED_AS_EVIDENCE = 'ACCEPTED_AS_EVIDENCE';
    case REJECTED = 'REJECTED';
    case NEEDS_CLARIFICATION = 'NEEDS_CLARIFICATION';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
