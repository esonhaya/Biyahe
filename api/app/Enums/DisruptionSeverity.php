<?php

namespace App\Enums;

enum DisruptionSeverity: string
{
    case INFO = 'INFO';
    case MINOR = 'MINOR';
    case MAJOR = 'MAJOR';
    case CRITICAL = 'CRITICAL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function rank(): int
    {
        return match ($this) {
            self::INFO => 0,
            self::MINOR => 1,
            self::MAJOR => 2,
            self::CRITICAL => 3,
        };
    }
}
