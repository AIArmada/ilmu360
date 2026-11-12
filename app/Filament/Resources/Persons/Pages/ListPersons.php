<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use App\Filament\Resources\Persons\PersonResource;
use App\Support\Persons\ReordersFeaturedPersons;
use Filament\Resources\Pages\ListRecords;

class ListPersons extends ListRecords
{
    use ReordersFeaturedPersons;

    #[\Override]
    protected static string $resource = PersonResource::class;
}
