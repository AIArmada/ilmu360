<?php

declare(strict_types=1);

namespace App\Filament\Resources\Venues\Pages;

use App\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\ListRecords;

final class ListVenues extends ListRecords
{
    protected static string $resource = VenueResource::class;
}
