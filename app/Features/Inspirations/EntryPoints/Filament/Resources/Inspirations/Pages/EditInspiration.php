<?php

declare(strict_types=1);

namespace App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\Pages;

use App\Features\Inspirations\Actions\DeleteInspirationAction;
use App\Features\Inspirations\Actions\SaveInspirationAction;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\InspirationResource;
use App\Features\Inspirations\Models\Inspiration;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditInspiration extends EditRecord
{
    #[\Override]
    protected static string $resource = InspirationResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Inspiration) {
            throw new \RuntimeException('Expected Filament record to be an Inspiration instance.');
        }

        return app(SaveInspirationAction::class)->handle($data, $record);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Inspiration $record): bool {
                    app(DeleteInspirationAction::class)->handle($record);

                    return true;
                }),
        ];
    }
}
