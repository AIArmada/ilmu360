<?php

namespace Tests\Feature;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RefactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_changes()
    {
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
        $this->assertFalse(Schema::hasTable('event_speaker'));
        $this->assertFalse(Schema::hasTable('event_participants'));
        $this->assertFalse(Schema::hasTable('notification_preferences'));
        $this->assertFalse(Schema::hasTable('notification_endpoints'));
        $this->assertFalse(Schema::hasTable('event_interests'));
        $this->assertFalse(Schema::hasTable('dawah_share_links'));
        $this->assertFalse(Schema::hasTable('notifications'));
    }

    public function test_speaker_post_nominal_logic()
    {
        $person = Person::factory()->create([
            'qualifications' => [
                ['degree' => 'PhD', 'institution' => 'Oxford'],
                ['degree' => 'MA', 'institution' => 'Cairo'],
            ],
        ]);

        // post_nominal is cast to array, not string
        $this->assertEquals(['PhD', 'MA'], $person->post_nominal);
    }

    public function test_speaker_avatar_url_behavior()
    {
        $person = Person::factory()->create();

        // Should be null by default as no media attached
        $this->assertNull($person->avatar_url);

        // We can't really test setting it because the column is gone and the accessor is read-only for media
    }

    public function test_relationships()
    {
        OwnerContext::withOwner(null, function (): void {
            $person = Person::factory()->create();
            $event = Event::factory()->create();
            $institution = Institution::factory()->create();

            $event->speakers()->attach($person);
            $institution->speakers()->attach($person);

            $this->assertTrue($event->speakers->contains($person));
            $this->assertTrue($institution->speakers->contains($person));
            $this->assertTrue($person->institutions->contains($institution));
        });
    }

    public function test_event_card_image_url_uses_speaker_fallback()
    {
        $person = null;
        $event = null;

        OwnerContext::withOwner(null, function () use (&$person, &$event): void {
            $person = Person::factory()->create();
            $event = Event::factory()->create();

            $event->speakers()->attach($person);

            $event->update(['institution_id' => null]);
        });

        $this->assertEquals(asset('images/placeholders/event.png'), $event->card_image_url);
    }
}
