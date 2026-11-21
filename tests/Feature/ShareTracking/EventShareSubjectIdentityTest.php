<?php

declare(strict_types=1);

use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Models\Event;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\Enums\ShareSubjectType;

it('reads share-subject facts through Events without transporting hydrated models', function (): void {
    $event = Event::factory()->create(['title' => 'Scalar Event Subject']);
    $id = (string) $event->getKey();

    $identity = app(EventScheduleReads::class)->shareIdentityById($id);

    expect($identity)->toBe(['id' => $id, 'title' => 'Scalar Event Subject'])
        ->and(ShareSubject::for(ShareSubjectType::Event->value, $identity['id'], $identity['title']))
        ->toEqual(new ShareSubject(type: 'event', id: $id, key: 'event:'.$id, title: 'Scalar Event Subject'))
        ->and(app(EventScheduleReads::class)->shareIdentityById('00000000-0000-0000-0000-000000000000'))
        ->toBeNull();
});
