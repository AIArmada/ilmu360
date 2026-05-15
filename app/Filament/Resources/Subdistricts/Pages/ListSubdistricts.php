<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subdistricts\Pages;

use App\Filament\Resources\Subdistricts\SubdistrictResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubdistricts extends ListRecords
{
    protected static string $resource = SubdistrictResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
