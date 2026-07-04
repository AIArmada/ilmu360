<?php

declare(strict_types=1);

namespace App\Filament\Resources\Venues\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\Venues\VenueResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewVenue extends ViewRecord
{
    protected static string $resource = VenueResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

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
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
