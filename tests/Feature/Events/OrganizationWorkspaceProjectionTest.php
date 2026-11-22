<?php

declare(strict_types=1);

use AIArmada\Organizations\Actions\CreateOrganizationAction;
use App\Features\Events\Api\Data\OrganizationWorkspaceEvent;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Models\Event;
use App\Models\User;

it('returns immutable organization workspace cards without exporting event models', function (): void {
    $owner = User::factory()->create();
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Projection Organization']);
    $event = Event::factory()->create([
        'owner_type' => $organization->getMorphClass(),
        'owner_id' => $organization->getKey(),
        'title' => 'Organization Workspace Projection',
        'status' => 'draft',
    ]);

    $cards = app(EventScheduleReads::class)->organizationWorkspaceEvents(
        $organization->getMorphClass(),
        (string) $organization->getKey(),
        20,
    );

    expect($cards)->toHaveCount(1)
        ->and($cards[0])->toBeInstanceOf(OrganizationWorkspaceEvent::class)
        ->and($cards[0]->id)->toBe((string) $event->getKey())
        ->and($cards[0]->title)->toBe('Organization Workspace Projection')
        ->and($cards[0]->publiclyReachable)->toBeFalse()
        ->and($cards[0]->publicUrl)->toBeNull()
        ->and($cards[0]->canEdit)->toBeFalse()
        ->and($cards[0]->canManageAdmissions)->toBeFalse();
});
