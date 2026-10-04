<?php

namespace App\Filament\Resources\ProvenanceSources\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProvenanceSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('source_type')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                TextInput::make('publisher'),
                Textarea::make('url')
                    ->columnSpanFull(),
                DatePicker::make('accessed_at'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
