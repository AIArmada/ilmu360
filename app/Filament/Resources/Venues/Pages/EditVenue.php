<?php

namespace App\Filament\Resources\Venues\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Venues\SaveVenueAction;
use App\Filament\Resources\Venues\VenueResource;
use App\Models\Venue;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVenue extends EditRecord
{
    protected static string $resource = VenueResource::class;

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
            DeleteAction::make(),
        ];
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Venue) {
            abort(403);
        }

        return OwnerContext::withOwner(null, fn (): Model => app(SaveVenueAction::class)->handle($data, $record));
    }
}
