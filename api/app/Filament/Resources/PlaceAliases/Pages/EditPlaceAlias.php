<?php

namespace App\Filament\Resources\PlaceAliases\Pages;

use App\Filament\Resources\PlaceAliases\PlaceAliasResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlaceAlias extends EditRecord
{
    protected static string $resource = PlaceAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
