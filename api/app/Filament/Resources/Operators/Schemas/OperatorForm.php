<?php

namespace App\Filament\Resources\Operators\Schemas;

use App\Enums\VerificationState;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class OperatorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                Select::make('verification_state')
                    ->options(VerificationState::class)
                    ->default('DRAFT')
                    ->required(),
                Toggle::make('is_demo')
                    ->required(),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
            ]);
    }
}
