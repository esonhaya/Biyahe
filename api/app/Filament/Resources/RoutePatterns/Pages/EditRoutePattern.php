<?php

namespace App\Filament\Resources\RoutePatterns\Pages;

use App\Filament\Resources\RoutePatterns\RoutePatternResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRoutePattern extends EditRecord
{
    protected static string $resource = RoutePatternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
