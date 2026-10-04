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
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Stable IDs are assigned by reviewed dataset imports.'),
                TextInput::make('publisher'),
                Textarea::make('url')
                    ->helperText('Optional. Record a reference even when a source has no public URL.')
                    ->columnSpanFull(),
                DatePicker::make('accessed_at'),
                DatePicker::make('checked_at')
                    ->required(),
                DatePicker::make('effective_from'),
                DatePicker::make('effective_until'),
                TextInput::make('license')
                    ->helperText('Record redistribution terms such as ODbL, or state why none applies.'),
                TextInput::make('verification_method')
                    ->helperText('Describe how the fact was checked.'),
                TextInput::make('verified_by')
                    ->helperText('Person, team, or process that checked the source.'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
