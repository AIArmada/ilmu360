<?php

namespace App\Filament\Resources\TitleIssuers\Pages;

use App\Filament\Resources\TitleIssuers\TitleIssuerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTitleIssuer extends EditRecord
{
    protected static string $resource = TitleIssuerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
