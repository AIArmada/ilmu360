<?php

declare(strict_types=1);

namespace App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\Pages;

use App\Features\Inspirations\Actions\SaveInspirationAction;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\InspirationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInspiration extends CreateRecord
{
    #[\Override]
    protected static string $resource = InspirationResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveInspirationAction::class)->handle($data);
    }
}
