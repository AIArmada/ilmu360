<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Filament\Resources\Spaces\Pages;

use App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSpace extends ViewRecord
{
    #[\Override]
    protected static string $resource = SpaceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
