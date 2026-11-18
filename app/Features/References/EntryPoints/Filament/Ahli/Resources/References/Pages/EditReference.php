<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Filament\Ahli\Resources\References\Pages;

use App\Features\References\EntryPoints\Filament\Ahli\Resources\References\ReferenceResource;

class EditReference extends \App\Features\References\EntryPoints\Filament\Resources\References\Pages\EditReference
{
    #[\Override]
    protected static string $resource = ReferenceResource::class;
}
