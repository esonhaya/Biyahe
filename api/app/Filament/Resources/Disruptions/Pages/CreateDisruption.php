<?php

namespace App\Filament\Resources\Disruptions\Pages;

use App\Filament\Resources\Disruptions\DisruptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDisruption extends CreateRecord
{
    protected static string $resource = DisruptionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
