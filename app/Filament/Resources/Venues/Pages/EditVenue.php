<?php

namespace App\Filament\Resources\Venues\Pages;

use App\Actions\Venues\SaveVenueAction;
use App\Filament\Resources\Venues\VenueResource;
use App\Models\Venue;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVenue extends EditRecord
{
    protected static string $resource = VenueResource::class;

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

        return app(SaveVenueAction::class)->handle($data, $record);
    }
}
