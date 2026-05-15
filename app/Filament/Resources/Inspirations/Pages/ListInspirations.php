<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inspirations\Pages;

use App\Filament\Resources\Inspirations\InspirationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInspirations extends ListRecords
{
    protected static string $resource = InspirationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
