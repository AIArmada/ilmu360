<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Events\Models\Event;
use App\Features\Persons\Models\Person;
use Illuminate\Support\Facades\Schema;

test('schema no longer contains removed columns and tables', function (): void {
    expect(Schema::hasColumn('events', 'person_id'))->toBeFalse()
        ->and(Schema::hasColumn('events', 'parent_event_id'))->toBeFalse()
        ->and(Schema::hasColumn('events', 'event_structure'))->toBeFalse()
        ->and(Schema::hasTable('event_involvements'))->toBeTrue()
        ->and(Schema::hasTable('event_attendances'))->toBeTrue()
        ->and(Schema::hasTable('contribution_requests'))->toBeTrue()
        ->and(Schema::hasTable('membership_applications'))->toBeTrue()
        ->and(Schema::hasTable('reference_members'))->toBeTrue()
        ->and(Schema::hasTable('membership_invitations'))->toBeTrue()
        ->and(Schema::hasColumn('membership_invitations', 'channel'))->toBeTrue()
        ->and(Schema::hasColumn('membership_invitations', 'recipient'))->toBeTrue()
        ->and(Schema::hasColumn('membership_invitations', 'email'))->toBeFalse()
        ->and(Schema::hasTable('notification_settings'))->toBeFalse()
        ->and(Schema::hasTable('notification_rules'))->toBeFalse()
        ->and(Schema::hasTable('notification_destinations'))->toBeFalse()
        ->and(Schema::hasTable('notification_deliveries'))->toBeFalse()
        ->and(Schema::hasColumn('moderation_reviews', 'reviewer_id'))->toBeFalse()
        ->and(Schema::hasTable('event_person'))->toBeFalse()
        ->and(Schema::hasTable('event_participants'))->toBeFalse()
        ->and(Schema::hasTable('notification_preferences'))->toBeFalse()
        ->and(Schema::hasTable('notification_endpoints'))->toBeFalse()
        ->and(Schema::hasTable('event_interests'))->toBeFalse()
        ->and(Schema::hasTable('notifications'))->toBeFalse();
});

test('person avatar url is null by default', function (): void {
    $person = Person::factory()->create();

    // Should be null by default as no media attached
    expect($person->avatar_url)->toBeNull();

    // We can't really test setting it because the column is gone and the accessor is read-only for media
});

test('event card image url uses default placeholder', function (): void {
    $person = null;
    $event = null;

    OwnerContext::withOwner(null, function () use (&$person, &$event): void {
        $person = Person::factory()->create();
        $event = Event::factory()->create();

        $event->update(['institution_id' => null]);
    });

    expect($event->card_image_url)->toEqual(asset('images/placeholders/event-wide-v1.jpg'));
});
