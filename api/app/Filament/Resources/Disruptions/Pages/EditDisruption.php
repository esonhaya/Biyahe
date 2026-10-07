<?php

namespace App\Filament\Resources\Disruptions\Pages;

use App\Filament\Resources\Disruptions\DisruptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDisruption extends EditRecord
{
    protected static string $resource = DisruptionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
