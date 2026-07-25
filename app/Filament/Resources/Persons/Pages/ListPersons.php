<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use App\Filament\Resources\Persons\PersonResource;
use Filament\Resources\Pages\ListRecords;

class ListPersons extends ListRecords
{
    protected static string $resource = PersonResource::class;
}
