<?php

namespace App\Filament\Resources\FieldReviewActions\Pages;

use App\Filament\Resources\FieldReviewActions\FieldReviewActionResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageFieldReviewActions extends ManageRecords
{
    protected static string $resource = FieldReviewActionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
