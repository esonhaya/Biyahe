<?php

namespace App\Filament\Support;

use App\Enums\VerificationState;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;

final class VerificationTableFilters
{
    /** @return list<SelectFilter|TernaryFilter> */
    public static function make(): array
    {
        return [
            SelectFilter::make('verification_state')
                ->options(VerificationState::class)
                ->label('Verification state'),
            TernaryFilter::make('is_demo')
                ->label('Demo record')
                ->trueLabel('Demo only')
                ->falseLabel('Non-demo only'),
        ];
    }
}
