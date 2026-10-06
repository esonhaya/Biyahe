<?php

namespace App\Filament\Resources\FieldObservations\Pages;

use App\Filament\Resources\FieldObservations\FieldObservationResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFieldObservations extends ManageRecords
{
    protected static string $resource = FieldObservationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
