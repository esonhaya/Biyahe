<?php

namespace App\Filament\Resources\Disruptions;

use App\Enums\DisruptionCategory;
use App\Enums\DisruptionSeverity;
use App\Enums\DisruptionSourceClass;
use App\Enums\DisruptionStatus;
use App\Filament\Resources\Disruptions\Pages\CreateDisruption;
use App\Filament\Resources\Disruptions\Pages\EditDisruption;
use App\Filament\Resources\Disruptions\Pages\ListDisruptions;
use App\Models\Disruption;
use App\Services\Disruptions\DisruptionLifecycleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DisruptionResource extends Resource
{
    protected static ?string $model = Disruption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $recordTitleAttribute = 'headline';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('stable_id')->required()->maxLength(150),
            Select::make('category')->options(self::enumOptions(DisruptionCategory::class))->required(),
            Select::make('severity')->options(self::enumOptions(DisruptionSeverity::class))->required()->default(DisruptionSeverity::INFO->value),
            Select::make('status')->options(self::enumOptions(DisruptionStatus::class))->disabled()->dehydrated(false)->default(DisruptionStatus::DRAFT->value),
            Select::make('source_class')->options(self::enumOptions(DisruptionSourceClass::class))->required(),
            Select::make('provenance_source_id')->relationship('provenanceSource', 'title')->required(),
            TextInput::make('headline')->required()->maxLength(180),
            Textarea::make('description')->required()->maxLength(5000)->columnSpanFull(),
            DateTimePicker::make('starts_at'),
            DateTimePicker::make('ends_at'),
            Select::make('routes')->relationship('routes', 'code')->multiple()->preload(),
            Select::make('patterns')->relationship('patterns', 'name')->multiple()->preload(),
            Select::make('stops')->relationship('stops', 'name')->multiple()->preload(),
            Select::make('places')->relationship('places', 'name')->multiple()->preload(),
            Select::make('transfers')->relationship('transfers', 'stable_id')->multiple()->preload(),
            Textarea::make('geometry')->label('Optional WGS84 geometry WKT')->helperText('Use only reviewed POINT/LINESTRING/MULTILINESTRING/POLYGON/MULTIPOLYGON WKT.')->columnSpanFull(),
            Textarea::make('review_notes')->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('stable_id'),
            TextEntry::make('headline'),
            TextEntry::make('category')->badge(),
            TextEntry::make('severity')->badge(),
            TextEntry::make('status')->badge(),
            TextEntry::make('source_class')->badge(),
            TextEntry::make('provenanceSource.title')->label('Provenance'),
            TextEntry::make('starts_at')->dateTime(),
            TextEntry::make('ends_at')->dateTime(),
            TextEntry::make('description')->columnSpanFull(),
            TextEntry::make('review_notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('headline')->searchable()->wrap(),
                TextColumn::make('category')->badge()->sortable(),
                TextColumn::make('severity')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('source_class')->badge()->sortable(),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->sortable(),
                TextColumn::make('provenanceSource.title')->label('Source')->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::enumOptions(DisruptionStatus::class)),
                SelectFilter::make('severity')->options(self::enumOptions(DisruptionSeverity::class)),
                SelectFilter::make('category')->options(self::enumOptions(DisruptionCategory::class)),
                SelectFilter::make('source_class')->options(self::enumOptions(DisruptionSourceClass::class)),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('activate')->label('Activate')->color('success')->requiresConfirmation()
                    ->visible(fn (Disruption $record): bool => in_array($record->status, [DisruptionStatus::DRAFT, DisruptionStatus::SCHEDULED], true))
                    ->action(fn (Disruption $record) => app(DisruptionLifecycleService::class)->transition($record, DisruptionStatus::ACTIVE, auth()->user(), 'Activated from admin.')),
                Action::make('schedule')->label('Schedule')->requiresConfirmation()
                    ->visible(fn (Disruption $record): bool => in_array($record->status, [DisruptionStatus::DRAFT, DisruptionStatus::ACTIVE], true))
                    ->action(fn (Disruption $record) => app(DisruptionLifecycleService::class)->transition($record, DisruptionStatus::SCHEDULED, auth()->user(), 'Scheduled from admin.')),
                Action::make('resolve')->label('Resolve')->color('warning')->requiresConfirmation()
                    ->visible(fn (Disruption $record): bool => in_array($record->status, [DisruptionStatus::ACTIVE, DisruptionStatus::SCHEDULED], true))
                    ->form([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (Disruption $record, array $data) => app(DisruptionLifecycleService::class)->transition($record, DisruptionStatus::RESOLVED, auth()->user(), $data['reason'])),
                Action::make('reject')->label('Reject')->color('danger')->requiresConfirmation()
                    ->visible(fn (Disruption $record): bool => ! in_array($record->status, [DisruptionStatus::RESOLVED, DisruptionStatus::REJECTED], true))
                    ->form([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (Disruption $record, array $data) => app(DisruptionLifecycleService::class)->transition($record, DisruptionStatus::REJECTED, auth()->user(), $data['reason'])),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisruptions::route('/'),
            'create' => CreateDisruption::route('/create'),
            'edit' => EditDisruption::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function enumOptions(string $enum): array
    {
        return array_combine(array_map(static fn ($case): string => $case->value, $enum::cases()), array_map(static fn ($case): string => str_replace('_', ' ', $case->value), $enum::cases()));
    }
}
