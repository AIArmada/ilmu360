<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Filament\Resources\Venues\Pages;

use App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\ListRecords;

final class ListVenues extends ListRecords
{
    #[\Override]
    protected static string $resource = VenueResource::class;
}
