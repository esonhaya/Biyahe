<?php

namespace App\Filament\Resources\Disruptions\Pages;

use App\Filament\Resources\Disruptions\DisruptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDisruptions extends ListRecords
{
    protected static string $resource = DisruptionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
