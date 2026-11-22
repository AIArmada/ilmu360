<?php

declare(strict_types=1);

use App\Features\Events\Api\Data\PersonDashboardEvent;
use App\Features\Events\Api\Data\PersonDashboardEventPage;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Models\Event;
use App\Features\Persons\Models\Person;

it('projects speaker workspace events through an immutable owner boundary', function (): void {
    $person = Person::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Speaker Workspace Projection',
        'status' => 'draft',
    ]);

    $event->persons()->attach($person->getKey());

    $page = app(EventScheduleReads::class)->personDashboardEventsPage(
        personId: (string) $person->getKey(),
        search: 'Speaker Workspace',
        status: 'draft',
        perPage: 8,
        page: 1,
    );

    expect($page)->toBeInstanceOf(PersonDashboardEventPage::class)
        ->and($page->total)->toBe(1)
        ->and($page->perPage)->toBe(8)
        ->and($page->currentPage)->toBe(1)
        ->and($page->items)->toHaveCount(1)
        ->and($page->items[0])->toBeInstanceOf(PersonDashboardEvent::class)
        ->and($page->items[0]->id)->toBe((string) $event->getKey())
        ->and($page->items[0]->title)->toBe('Speaker Workspace Projection')
        ->and($page->items[0]->publiclyReachable)->toBeFalse()
        ->and($page->items[0]->publicUrl)->toBeNull()
        ->and($page->items[0]->canEdit)->toBeFalse();

    $excluded = app(EventScheduleReads::class)->personDashboardEventsPage(
        personId: (string) $person->getKey(),
        search: 'Speaker Workspace',
        status: 'approved',
        perPage: 8,
        page: 1,
    );

    expect($excluded->items)->toBe([])
        ->and($excluded->total)->toBe(0);
});
