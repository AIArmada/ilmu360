<?php

namespace App\Filament\Resources\Venues\Pages;

use App\Actions\Venues\SaveVenueAction;
use App\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVenue extends CreateRecord
{
    protected static string $resource = VenueResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveVenueAction::class)->handle($data);
    }
}
