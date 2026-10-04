<?php

namespace App\Filament\Resources\RoutePatternStops\Pages;

use App\Filament\Resources\RoutePatternStops\RoutePatternStopResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoutePatternStops extends ListRecords
{
    protected static string $resource = RoutePatternStopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
