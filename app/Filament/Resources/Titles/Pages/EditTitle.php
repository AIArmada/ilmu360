<?php

declare(strict_types=1);

namespace App\Filament\Resources\Titles\Pages;

use App\Filament\Resources\Titles\TitleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTitle extends EditRecord
{
    protected static string $resource = TitleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
