<?php

declare(strict_types=1);

namespace App\Filament\Resources\Venues\Pages;

use App\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewVenue extends ViewRecord
{
    protected static string $resource = VenueResource::class;
}
