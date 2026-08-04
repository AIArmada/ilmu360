<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentPersons\Resources\PersonResource\Pages\ViewPerson as PackageViewPerson;
use App\Filament\Resources\Persons\PersonResource;

class ViewPerson extends PackageViewPerson
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
}
