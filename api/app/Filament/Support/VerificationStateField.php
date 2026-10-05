<?php

namespace App\Filament\Support;

use App\Enums\VerificationState;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

final class VerificationStateField
{
    public static function make(string $name = 'verification_state', ?string $label = null): Select
    {
        $field = Select::make($name)
            ->options(VerificationState::class)
            ->default(VerificationState::DRAFT->value)
            ->required()
            ->live()
            ->disableOptionWhen(
                fn (string $value, Get $get): bool => $value === VerificationState::VERIFIED->value && $get->boolean('is_demo'),
            )
            ->helperText('VERIFIED requires a non-demo record with checked provenance. Use UNVERIFIED when service evidence is incomplete.');

        if ($label !== null) {
            $field->label($label);
        }

        return $field;
    }
}
