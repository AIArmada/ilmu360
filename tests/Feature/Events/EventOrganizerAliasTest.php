<?php

declare(strict_types=1);

use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Platform\Moderation\Models\ModerationReview;
use App\Models\User;
use App\Features\Events\Services\OrganizerResolver;

it('writes organizer involvements and moderation actors using configured morph aliases', function (): void {
    $event = Event::factory()->create();
    $institution = Institution::factory()->create();

    $event->setPrimaryOrganizer($institution);

    $involvement = $event->fresh()->primaryOrganizerInvolvement;
    $institutionAlias = (new Institution)->getMorphClass();

    expect($involvement?->involveable_type)->toBe($institutionAlias)
        ->and(OrganizerResolver::involvementMatchesInstitution(
            $involvement?->involveable_type,
            $involvement?->involveable_id,
            (string) $institution->getKey(),
        ))->toBeTrue()
        ->and(ModerationReview::factory()->create()->actioned_by_type)->toBe((new User)->getMorphClass());
});
