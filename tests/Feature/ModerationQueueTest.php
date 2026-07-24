<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\EventEscalationType;
use AIArmada\Events\Models\EventEscalation;
use App\Filament\Pages\ModerationQueue;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('shows verification warnings in moderation queue', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'unverified']);
    $person = Person::factory()->create(['status' => 'pending']);

    $event = Event::factory()->create([
        'status' => 'pending',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
    ]);
    $event->speakers()->attach($person);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful()
        ->assertSee('Venue')
        ->assertSee($venue->name)
        ->assertSee('Unverified')
        ->assertSee('1 unverified');
});

it('does not expose a redundant event status column in moderation queue', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    Event::factory()->create([
        'status' => 'pending',
    ]);

    Livewire::actingAs($moderator)
        ->test(ModerationQueue::class)
        ->assertTableColumnDoesNotExist('status')
        ->assertTableColumnExists('priority')
        ->assertTableColumnExists('venue.name');
});

it('shows priority events first in moderation queue', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $priorityEvent = Event::factory()->create([
        'title' => 'Priority Queue Event',
        'status' => 'pending',
        'starts_at' => now()->addHours(5),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    EventEscalation::create([
        'event_id' => $priorityEvent->id,
        'type' => EventEscalationType::Priority,
        'decision_key' => $priorityEvent->id.':priority',
    ]);

    $normalEvent = Event::factory()->create([
        'title' => 'Normal Queue Event',
        'status' => 'pending',
        'starts_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($moderator)
        ->test(ModerationQueue::class)
        ->assertCanSeeTableRecords([$priorityEvent, $normalEvent], true);
});

it('shows pending references in moderation queue reference status', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);
    $pendingReference = Reference::factory()->create([
        'title' => 'Pending Reference For Queue',
        'status' => 'pending',
    ]);

    $event = Event::factory()->create([
        'status' => 'pending',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
    ]);
    $event->speakers()->attach($person);
    $event->references()->attach($pendingReference);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful()
        ->assertSee('References Status')
        ->assertSee('1 unverified')
        ->assertSee('Pending Reference For Queue');
});

it('shows all verified when moderation queue event references are already approved', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);
    $verifiedReference = Reference::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'pending',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
    ]);
    $event->speakers()->attach($person);
    $event->references()->attach($verifiedReference);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful()
        ->assertSee('References Status')
        ->assertSee('All verified');
});

it('shows none when moderation queue event has no references', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    $event = Event::factory()->create([
        'status' => 'pending',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
    ]);
    $event->speakers()->attach($person);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful()
        ->assertSee('References Status')
        ->assertSee('None');
});

it('shows only needs changes events on the needs changes tab', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $pendingEvent = Event::factory()->create([
        'title' => 'Pending Queue Event',
        'status' => 'pending',
    ]);

    $needsChangesEvent = Event::factory()->create([
        'title' => 'Needs Changes Queue Event',
        'status' => 'needs_changes',
    ]);

    OwnerContext::withOwner(null, fn () => $needsChangesEvent->moderationReviews()->create([
        'actionable_type' => Event::class,
        'actioned_by_type' => User::class,
        'actioned_by_id' => $moderator->id,
        'type' => 'changes_requested',
        'reason' => 'incomplete_info',
        'notes' => 'Please update venue details.',
    ]));

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue?tab=needs_changes')
        ->assertSuccessful()
        ->assertSee($needsChangesEvent->title)
        ->assertSee('Please update venue details.')
        ->assertSee('Return to Pending')
        ->assertDontSee($pendingEvent->title);
});

it('shows only events with open reports on the reports tab', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $reporter = User::factory()->create();

    $reportedEvent = Event::factory()->create([
        'title' => 'Reported Approved Event',
        'status' => 'approved',
    ]);

    $reportedEvent->reports()->create([
        'reporter_id' => $reporter->id,
        'category' => 'other',
        'description' => 'Potentially misleading event information.',
        'status' => 'open',
    ]);

    $notOpenReportedEvent = Event::factory()->create([
        'title' => 'Resolved Report Event',
        'status' => 'pending',
    ]);

    $notOpenReportedEvent->reports()->create([
        'reporter_id' => $reporter->id,
        'handled_by' => $moderator->id,
        'category' => 'other',
        'description' => 'Already handled.',
        'status' => 'resolved',
        'resolution_note' => 'Reviewed and resolved.',
    ]);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue?tab=reports')
        ->assertSuccessful()
        ->assertSee($reportedEvent->title)
        ->assertDontSee($notOpenReportedEvent->title);
});

it('links moderation queue view action to the event infolist page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $event = Event::factory()->create([
        'status' => 'pending',
        'title' => 'Pending Event For View Link',
    ]);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful()
        ->assertSee("/admin/events/{$event->id}");
});
