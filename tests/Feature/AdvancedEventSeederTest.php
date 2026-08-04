<?php

use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventRole;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Events\Models\VenueSpaceType;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Space;
use App\Models\Venue;
use Database\Seeders\AdvancedEventSeeder;
use Database\Seeders\AIArmada\FoundationSeeder;
use Database\Seeders\EventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds package occurrences sessions roles and prayer expressions', function (): void {
    $this->seed(FoundationSeeder::class);

    Institution::factory()->create(['status' => 'verified']);
    Person::factory()->count(3)->create(['status' => 'verified']);

    $this->seed(AdvancedEventSeeder::class);

    $event = Event::query()
        ->where('title', 'Program Ramadan: Tadabbur & Qiyam')
        ->latest('created_at')
        ->firstOrFail();

    $occurrence = $event->occurrences()->first();
    $session = $occurrence?->sessions()->first();

    expect($occurrence)->not->toBeNull()
        ->and($session)->not->toBeNull();

    if ($occurrence === null || $session === null) {
        return;
    }

    $involvement = EventInvolvement::query()
        ->where('event_session_id', $session->id)
        ->where('role_code', EventKeyPersonRole::Speaker->value)
        ->first();
    $expression = EventTimeExpression::query()
        ->where('event_session_id', $session->id)
        ->where('anchor_type', 'prayer')
        ->first();

    expect(EventRole::query()->whereKey($involvement?->event_role_id)->value('code'))
        ->toBe(EventKeyPersonRole::Speaker->value)
        ->and($involvement?->event_occurrence_id)->toBe($occurrence->id)
        ->and($involvement?->event_session_id)->toBe($session->id)
        ->and($expression?->event_occurrence_id)->toBe($occurrence->id)
        ->and($expression?->event_session_id)->toBe($session->id)
        ->and($expression?->display_label)->toBe('Selepas Tarawih');

    $location = $event->fresh()->load('primaryLocation.venueSpace')->primaryLocation;

    expect($location)->not->toBeNull()
        ->and($location?->venue_space_id)->not->toBeNull()
        ->and($location?->venueSpace?->name)->toBe('Dewan Utama')
        ->and($location?->venueSpace?->venue_id)->toBeNull()
        ->and($location?->space_name_snapshot)->toBe('Dewan Utama')
        ->and((string) $location?->venue_space_type_id)
        ->toBe((string) VenueSpaceType::query()->where('code', 'hall')->value('id'))
        ->and($event->institution_id)->not->toBeNull();

    $space = Space::query()->find($location?->venue_space_id);

    expect($space?->institutions()->whereKey($event->institution_id)->exists())->toBeTrue();
});

it('seeds venue-owned events with a venue-owned venue space', function (): void {
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'institution_id' => null,
        'default_venue_id' => $venue->getKey(),
        'delivery_mode' => 'physical',
    ]);

    $seeder = new class extends EventSeeder
    {
        public function syncVenueLocation(Event $event, Venue $venue): void
        {
            $this->syncSeededEventLocation($event, venue: $venue);
        }
    };

    $seeder->syncVenueLocation($event, $venue);

    $location = $event->fresh()->load('primaryLocation.venueSpace')->primaryLocation;

    expect($location)->not->toBeNull()
        ->and($location?->venue_id)->toBe($venue->getKey())
        ->and($location?->venue_space_id)->not->toBeNull()
        ->and($location?->venueSpace?->venue_id)->toBe($venue->getKey());
});
