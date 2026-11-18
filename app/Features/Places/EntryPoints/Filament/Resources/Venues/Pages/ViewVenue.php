<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Filament\Resources\Venues\Pages;

use App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewVenue extends ViewRecord
{
    #[\Override]
    protected static string $resource = VenueResource::class;
}
