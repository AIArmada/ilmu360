<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\Person;
use Illuminate\Support\Facades\Schema;

test('schema no longer contains removed columns and tables', function (): void {
    $this->assertFalse(Schema::hasColumn('events', 'person_id'), 'person_id should not exist in events table');
    $this->assertFalse(Schema::hasColumn('events', 'parent_event_id'));
    $this->assertFalse(Schema::hasColumn('events', 'event_structure'));
    $this->assertTrue(Schema::hasTable('event_involvements'));
    $this->assertTrue(Schema::hasTable('event_attendances'));
    $this->assertTrue(Schema::hasTable('contribution_requests'));
    $this->assertFalse(Schema::hasTable('membership_claims'));
    $this->assertTrue(Schema::hasTable('reference_members'));
    $this->assertTrue(Schema::hasTable('membership_invitations'));
    $this->assertFalse(Schema::hasTable('notification_settings'));
    $this->assertFalse(Schema::hasTable('notification_rules'));
    $this->assertFalse(Schema::hasTable('notification_destinations'));
    $this->assertFalse(Schema::hasTable('notification_deliveries'));
    $this->assertFalse(Schema::hasColumn('moderation_reviews', 'reviewer_id'));
    $this->assertFalse(Schema::hasTable('event_person'));
    $this->assertFalse(Schema::hasTable('event_participants'));
    $this->assertFalse(Schema::hasTable('notification_preferences'));
    $this->assertFalse(Schema::hasTable('notification_endpoints'));
    $this->assertFalse(Schema::hasTable('event_interests'));
    $this->assertFalse(Schema::hasTable('dawah_share_links'));
    $this->assertFalse(Schema::hasTable('notifications'));
});

test('person avatar url is null by default', function (): void {
    $person = Person::factory()->create();

    // Should be null by default as no media attached
    $this->assertNull($person->avatar_url);

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

    $this->assertEquals(asset('images/placeholders/event.png'), $event->card_image_url);
});
