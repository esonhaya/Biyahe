<?php

namespace App\Filament\Resources\ProvenanceSources\Pages;

use App\Filament\Resources\ProvenanceSources\ProvenanceSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProvenanceSource extends EditRecord
{
    protected static string $resource = ProvenanceSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
