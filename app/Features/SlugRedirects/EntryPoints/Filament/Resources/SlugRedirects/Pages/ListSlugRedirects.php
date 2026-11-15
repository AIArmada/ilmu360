<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Pages;

use App\Features\SlugRedirects\Api\SlugRedirectAdministration;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\SlugRedirectResource;
use App\Features\SlugRedirects\Models\SlugRedirect;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSlugRedirects extends ListRecords
{
    #[\Override]
    protected static string $resource = SlugRedirectResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data): SlugRedirect {
                    $redirectId = app(SlugRedirectAdministration::class)->create(
                        is_string($data['redirectable_type'] ?? null) ? $data['redirectable_type'] : null,
                        is_string($data['redirectable_id'] ?? null) ? $data['redirectable_id'] : null,
                        is_string($data['source_slug'] ?? null) ? $data['source_slug'] : null,
                    );

                    return SlugRedirect::query()->findOrFail($redirectId);
                }),
        ];
    }
}
