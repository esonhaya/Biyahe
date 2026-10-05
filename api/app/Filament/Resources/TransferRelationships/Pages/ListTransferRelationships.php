<?php

namespace App\Filament\Resources\TransferRelationships\Pages;

use App\Filament\Resources\TransferRelationships\TransferRelationshipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransferRelationships extends ListRecords
{
    protected static string $resource = TransferRelationshipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
