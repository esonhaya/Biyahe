<?php

namespace App\Enums;

enum DisruptionSourceClass: string
{
    case OFFICIAL = 'OFFICIAL';
    case OPERATOR = 'OPERATOR';
    case ADMIN_FIELD_REPORT = 'ADMIN_FIELD_REPORT';
    case TRUSTED_CONTRIBUTOR = 'TRUSTED_CONTRIBUTOR';
    case PUBLIC_REPORT = 'PUBLIC_REPORT';
    case OTHER_REVIEWED_SOURCE = 'OTHER_REVIEWED_SOURCE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function canHardClose(): bool
    {
        return in_array($this, [
            self::OFFICIAL,
            self::OPERATOR,
            self::ADMIN_FIELD_REPORT,
            self::TRUSTED_CONTRIBUTOR,
            self::OTHER_REVIEWED_SOURCE,
        ], true);
    }
}
