<?php

namespace App\Filament\Resources\ProvenanceSources\Pages;

use App\Filament\Resources\ProvenanceSources\ProvenanceSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProvenanceSources extends ListRecords
{
    protected static string $resource = ProvenanceSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
