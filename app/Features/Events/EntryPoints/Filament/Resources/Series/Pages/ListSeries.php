<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Series\Pages;

use App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeries extends ListRecords
{
    #[\Override]
    protected static string $resource = SeriesResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
