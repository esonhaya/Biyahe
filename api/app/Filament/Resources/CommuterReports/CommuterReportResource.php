<?php

namespace App\Filament\Resources\CommuterReports;

use App\Enums\CommuterReportCategory;
use App\Enums\CommuterReportStatus;
use App\Filament\Resources\CommuterReports\Pages\ManageCommuterReports;
use App\Models\CommuterReport;
use App\Services\Disruptions\CommuterReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommuterReportResource extends Resource
{
    protected static ?string $model = CommuterReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $recordTitleAttribute = 'stable_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('stable_id'),
            TextEntry::make('category')->badge(),
            TextEntry::make('status')->badge(),
            TextEntry::make('description')->columnSpanFull(),
            TextEntry::make('observed_at')->dateTime(),
            TextEntry::make('location_point')->label('Submitted location'),
            TextEntry::make('route.code')->label('Route'),
            TextEntry::make('stop.name')->label('Stop'),
            TextEntry::make('place.name')->label('Place'),
            TextEntry::make('review_notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('stable_id')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('description')->limit(80)->wrap(),
                TextColumn::make('route.code')->label('Route'),
                TextColumn::make('stop.name')->label('Stop'),
                TextColumn::make('observed_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
                SelectFilter::make('category')->options(array_combine(CommuterReportCategory::values(), CommuterReportCategory::values())),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('underReview')->label('Under review')->visible(fn (CommuterReport $record): bool => $record->status === CommuterReportStatus::SUBMITTED)
                    ->action(fn (CommuterReport $record) => app(CommuterReportService::class)->review($record, CommuterReportStatus::UNDER_REVIEW, auth()->user(), 'Moved to review.')),
                Action::make('acceptSignal')->label('Accept as signal')->color('success')->visible(fn (CommuterReport $record): bool => in_array($record->status, [CommuterReportStatus::SUBMITTED, CommuterReportStatus::UNDER_REVIEW], true))
                    ->form([Textarea::make('reason')->maxLength(2000)])
                    ->action(fn (CommuterReport $record, array $data) => app(CommuterReportService::class)->review($record, CommuterReportStatus::ACCEPTED_AS_SIGNAL, auth()->user(), $data['reason'] ?? null)),
                Action::make('promote')->label('Promote to advisory')->color('warning')->visible(fn (CommuterReport $record): bool => $record->status === CommuterReportStatus::ACCEPTED_AS_SIGNAL && $record->disruption_id === null)
                    ->requiresConfirmation()
                    ->form([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (CommuterReport $record, array $data) => app(CommuterReportService::class)->promoteToAdvisory($record, auth()->user(), $data['reason'])),
                Action::make('reject')->label('Reject')->color('danger')->visible(fn (CommuterReport $record): bool => in_array($record->status, [CommuterReportStatus::SUBMITTED, CommuterReportStatus::UNDER_REVIEW], true))
                    ->form([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (CommuterReport $record, array $data) => app(CommuterReportService::class)->review($record, CommuterReportStatus::REJECTED, auth()->user(), $data['reason'])),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommuterReports::route('/')];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return array_combine(CommuterReportStatus::values(), CommuterReportStatus::values());
    }
}
