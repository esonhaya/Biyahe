<?php

namespace App\Filament\Resources\RoutePatterns\Pages;

use App\Filament\Resources\RoutePatterns\RoutePatternResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoutePatterns extends ListRecords
{
    protected static string $resource = RoutePatternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
