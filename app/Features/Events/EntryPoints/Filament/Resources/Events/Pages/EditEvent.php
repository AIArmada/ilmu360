<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Events\Pages;

use App\Features\Events\Api\AdminEventWriter;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\Models\Event;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EditEvent extends EditRecord
{
    #[\Override]
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $event = $this->getRecord();

        if (! $event instanceof Event) {
            throw new LogicException('The Event resource can only edit event records.');
        }

        return array_replace(
            $data,
            app(EventAuthoring::class)->adminFormState((string) $event->getKey()),
        );
    }

    protected function handleRecordUpdate(Model $record, array $data): Event
    {
        if (! $record instanceof Event) {
            throw new LogicException('The Event resource can only update event records.');
        }

        return Event::query()->with(['accessPolicy', 'references', 'series', 'classifications', 'keyPeople', 'languages', 'media', 'institution', 'venue', 'primaryLocation.venueSpace'])->findOrFail(
            app(AdminEventWriter::class)->save($data, (string) $this->authenticatedUser()->getKey(), (string) $record->getKey()),
        );
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('An authenticated admin is required to save an event.');
        }

        return $user;
    }
}
