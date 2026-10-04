<?php

namespace App\Filament\Resources\Fares\Pages;

use App\Filament\Resources\Fares\FareResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFares extends ListRecords
{
    protected static string $resource = FareResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
