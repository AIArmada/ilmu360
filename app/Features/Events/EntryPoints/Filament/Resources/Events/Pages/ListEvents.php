<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Events\Pages;

use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\ListRecords;

final class ListEvents extends ListRecords
{
    #[\Override]
    protected static string $resource = EventResource::class;
}
