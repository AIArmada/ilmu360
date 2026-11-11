<?php

namespace App\Features\Events\Services;

use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Events\Actions\Authoring\SyncEventKeyPeopleAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Backwards-compatible key-person facade for callers that still resolve the
 * service directly; the write path is SyncEventKeyPeopleAction.
 */
class EventKeyPersonSyncService
{
    public function __construct(
        private readonly SyncEventKeyPeopleAction $syncKeyPeople,
    ) {}

    /**
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function sync(PackageEvent $event, array $personIds = [], array $otherKeyPeople = []): void
    {
        $this->syncKeyPeople->syncEvent($event, $personIds, $otherKeyPeople);
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function syncSession(EventSession $session, array $personIds = [], array $otherKeyPeople = []): void
    {
        $this->syncKeyPeople->syncSession($session, $personIds, $otherKeyPeople);
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function syncOccurrence(EventOccurrence $occurrence, array $personIds = [], array $otherKeyPeople = []): void
    {
        $this->syncKeyPeople->syncOccurrence($occurrence, $personIds, $otherKeyPeople);
    }

    public function syncOccurrenceOrganizer(EventOccurrence $occurrence, Model $organizer): void
    {
        $this->syncKeyPeople->syncOccurrenceOrganizer($occurrence, $organizer);
    }
}
