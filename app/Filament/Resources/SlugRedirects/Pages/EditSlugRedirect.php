<?php

namespace App\Filament\Resources\SlugRedirects\Pages;

use App\Filament\Resources\SlugRedirects\SlugRedirectResource;
use App\Models\SlugRedirect;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSlugRedirect extends EditRecord
{
    protected static string $resource = SlugRedirectResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var SlugRedirect $record */
        $record = $this->getRecord();

        return SlugRedirectResource::mutateRedirectData($data, $record);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
