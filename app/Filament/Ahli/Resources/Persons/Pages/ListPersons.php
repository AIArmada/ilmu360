<?php

declare(strict_types=1);

namespace App\Filament\Ahli\Resources\Persons\Pages;

use App\Filament\Ahli\Resources\Persons\PersonResource;
use Filament\Resources\Pages\ListRecords;

class ListPersons extends ListRecords
{
    protected static string $resource = PersonResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
