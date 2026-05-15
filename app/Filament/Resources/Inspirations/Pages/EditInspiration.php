<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inspirations\Pages;

use App\Filament\Resources\Inspirations\InspirationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInspiration extends EditRecord
{
    protected static string $resource = InspirationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
