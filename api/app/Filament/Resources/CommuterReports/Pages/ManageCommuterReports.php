<?php

namespace App\Filament\Resources\CommuterReports\Pages;

use App\Filament\Resources\CommuterReports\CommuterReportResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCommuterReports extends ManageRecords
{
    protected static string $resource = CommuterReportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
