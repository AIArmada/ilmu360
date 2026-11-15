<?php

namespace App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Pages;

use App\Features\SlugRedirects\Api\SlugRedirectAdministration;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\SlugRedirectResource;
use App\Features\SlugRedirects\Models\SlugRedirect;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSlugRedirect extends EditRecord
{
    #[\Override]
    protected static string $resource = SlugRedirectResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof SlugRedirect) {
            throw new \RuntimeException('Expected Filament record to be a SlugRedirect instance.');
        }

        $redirectId = (string) $record->getKey();
        app(SlugRedirectAdministration::class)->update(
            $redirectId,
            is_string($data['redirectable_type'] ?? null) ? $data['redirectable_type'] : null,
            is_string($data['redirectable_id'] ?? null) ? $data['redirectable_id'] : null,
            is_string($data['source_slug'] ?? null) ? $data['source_slug'] : null,
        );

        return SlugRedirect::query()->findOrFail($redirectId);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (SlugRedirect $record): bool => app(SlugRedirectAdministration::class)->delete((string) $record->getKey())),
        ];
    }
}
