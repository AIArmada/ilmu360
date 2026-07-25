<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\Persons\PersonResource;
use Filament\Resources\Pages\EditRecord;

class EditPerson extends EditRecord
{
    protected static string $resource = PersonResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    public function mount(int|string $record): void
    {
        OwnerContext::withOwner(null, function () use ($record): void {
            parent::mount($record);
        });
    }

    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['address']);

        return $data;
    }
}
