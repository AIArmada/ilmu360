<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\Pages;

use App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\PersonResource;
use App\Features\Persons\EntryPoints\Filament\Concerns\ReordersFeaturedPersons;
use Filament\Resources\Pages\ListRecords;

class ListPersons extends ListRecords
{
    use ReordersFeaturedPersons;

    #[\Override]
    protected static string $resource = PersonResource::class;
}
