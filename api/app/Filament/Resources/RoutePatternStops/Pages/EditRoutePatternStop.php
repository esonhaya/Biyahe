<?php

namespace App\Filament\Resources\RoutePatternStops\Pages;

use App\Filament\Resources\RoutePatternStops\RoutePatternStopResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRoutePatternStop extends EditRecord
{
    protected static string $resource = RoutePatternStopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
