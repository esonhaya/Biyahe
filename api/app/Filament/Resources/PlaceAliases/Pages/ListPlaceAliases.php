<?php

namespace App\Filament\Resources\PlaceAliases\Pages;

use App\Filament\Resources\PlaceAliases\PlaceAliasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlaceAliases extends ListRecords
{
    protected static string $resource = PlaceAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
