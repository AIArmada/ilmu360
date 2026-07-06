<?php

namespace App\Filament\Resources\Venues\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Venues\SaveVenueAction;
use App\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVenue extends CreateRecord
{
    protected static string $resource = VenueResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    public function mount(): void
    {
        OwnerContext::withOwner(null, function (): void {
            parent::mount();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return OwnerContext::withOwner(null, fn (): Model => app(SaveVenueAction::class)->handle($data));
    }
}
