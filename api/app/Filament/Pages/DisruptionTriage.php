<?php

namespace App\Filament\Pages;

use App\Services\Disruptions\PulseService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class DisruptionTriage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Disruption triage';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.disruption-triage';

    protected static ?string $title = 'Disruption triage';

    protected function getViewData(): array
    {
        return ['stats' => app(PulseService::class)->triage()];
    }
}
