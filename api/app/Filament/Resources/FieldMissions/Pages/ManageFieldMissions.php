<?php

namespace App\Filament\Resources\FieldMissions\Pages;

use App\Filament\Resources\FieldMissions\FieldMissionResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageFieldMissions extends ManageRecords
{
    protected static string $resource = FieldMissionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
