<?php

namespace App\Filament\Resources\TransportSurveySessions\Pages;

use App\Filament\Resources\TransportSurveySessions\TransportSurveySessionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTransportSurveySessions extends ManageRecords
{
    protected static string $resource = TransportSurveySessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
