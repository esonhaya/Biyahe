<?php

namespace App\Filament\Resources\TransferRelationships\Pages;

use App\Filament\Resources\TransferRelationships\TransferRelationshipResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransferRelationship extends EditRecord
{
    protected static string $resource = TransferRelationshipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
