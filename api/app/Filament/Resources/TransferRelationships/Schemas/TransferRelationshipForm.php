<?php

namespace App\Filament\Resources\TransferRelationships\Schemas;

use App\Enums\TransferReviewStatus;
use App\Filament\Support\VerificationStateField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TransferRelationshipForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('city_id')
                    ->relationship('city', 'name')
                    ->required(),
                Select::make('from_stop_id')
                    ->relationship('fromStop', 'name')
                    ->required(),
                Select::make('to_stop_id')
                    ->relationship('toStop', 'name')
                    ->required(),
                Select::make('from_route_pattern_id')
                    ->relationship('fromRoutePattern', 'name'),
                Select::make('to_route_pattern_id')
                    ->relationship('toRoutePattern', 'name'),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Assigned by the dataset importer; do not change manually.'),
                TextInput::make('relationship_type')
                    ->required()
                    ->helperText('Use a precise relationship type; proximity alone is not proof of a safe crossing.'),
                TextInput::make('distance_meters')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Straight-line screening estimate only; it is not a pedestrian route length.'),
                TextInput::make('landmark')
                    ->helperText('Optional supported landmark near the transfer.'),
                Textarea::make('practical_note')
                    ->helperText('Record only an evidenced transfer instruction; proximity alone is a candidate, not proof.')
                    ->columnSpanFull(),
                Select::make('review_status')
                    ->options(TransferReviewStatus::class)
                    ->default(TransferReviewStatus::PENDING_REVIEW->value)
                    ->required(),
                Textarea::make('review_notes')
                    ->required(fn (Get $get): bool => $get->get('review_status') === TransferReviewStatus::REJECTED->value)
                    ->helperText('Required when rejecting a candidate; explain the evidence or safety reason.')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                VerificationStateField::make(),
                Toggle::make('is_demo')
                    ->live()
                    ->required(),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
