<?php

declare(strict_types=1);

use App\Features\Events\Api\Data\InstitutionDashboardEventCard;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;

it('projects recent and pending institution events into immutable owner cards', function (): void {
    $institution = Institution::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Institution Workspace Card',
        'status' => 'pending',
    ]);

    $reader = app(EventScheduleReads::class);
    $recent = $reader->recentInstitutionEventCards((string) $institution->getKey(), 7);
    $pending = $reader->pendingInstitutionEventCards(
        institutionId: (string) $institution->getKey(),
        search: 'Institution Workspace',
        status: 'all',
        visibility: 'all',
        limit: 2,
    );

    expect($recent)->toHaveCount(1)
        ->and($recent[0])->toBeInstanceOf(InstitutionDashboardEventCard::class)
        ->and($recent[0]->id)->toBe((string) $event->getKey())
        ->and($recent[0]->title)->toBe('Institution Workspace Card')
        ->and($recent[0]->editUrl)->toBeNull()
        ->and($recent[0]->publicUrl)->toBeString()->not->toBeEmpty()
        ->and($pending)->toHaveCount(1)
        ->and($pending[0])->toBeInstanceOf(InstitutionDashboardEventCard::class)
        ->and($pending[0]->id)->toBe((string) $event->getKey());
});
