<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Data\EventEscalationType;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Governance\Models\EventEscalation;
use AIArmada\Events\Features\Governance\Models\EventSubmission;
use App\Features\Events\Api\EventModeration;
use App\Features\Events\EntryPoints\Filament\Pages\ModerationQueue;
use App\Features\Events\EntryPoints\Filament\Resources\Events\Pages\ViewEvent;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use App\Features\References\Models\Reference;
use App\Features\Reports\Models\Report;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\ModelStates\Exceptions\TransitionNotFound;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('shows verification warnings in moderation queue', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create(['status' => 'verified']);
    $venue = Venue::factory()->create(['status' => 'pending']);
    $person = Person::factory()->create(['status' => 'pending']);

    $event = Event::factory()->create([
        'status' => 'pending',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
    ]);
    $event->persons()->attach($person);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful()
        ->assertSee('Venue')
        ->assertSee($venue->name)
        ->assertSee('Pending')
        ->assertSee('1 needs review');
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

it('rebuilds the table when switching between event and occurrence moderation queues', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $pendingEvent = Event::factory()->create([
        'title' => 'Parent Event Awaiting Review',
        'status' => 'pending',
    ]);
    $approvedEvent = Event::factory()->create([
        'title' => 'Approved Parent For Occurrence',
        'status' => 'approved',
    ]);
    $occurrence = EventOccurrence::factory()->create([
        'event_id' => $approvedEvent->getKey(),
        'title' => 'Occurrence Awaiting Review',
        'status' => 'draft',
        'visibility' => 'private',
    ]);
    $submission = EventSubmission::query()->create([
        'event_id' => $approvedEvent->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'event_session_id' => null,
        'status' => 'pending',
        'submitted_at' => now(),
    ]);

    Livewire::actingAs($moderator)
        ->test(ModerationQueue::class)
        ->assertTableColumnExists('priority')
        ->assertTableColumnDoesNotExist('occurrence.title')
        ->assertCanSeeTableRecords([$pendingEvent])
        ->assertTableActionExists('approve', record: $pendingEvent)
        ->call('setActiveTab', 'pending_occurrences')
        ->assertSet('activeTab', 'pending_occurrences')
        ->assertTableColumnExists('occurrence.title')
        ->assertTableColumnExists('event.title')
        ->assertTableColumnDoesNotExist('priority')
        ->assertCanSeeTableRecords([$submission])
        ->assertTableActionExists('approve_occurrence', record: $submission)
        ->call('setActiveTab', 'pending')
        ->assertSet('activeTab', 'pending')
        ->assertTableColumnExists('priority')
        ->assertTableColumnDoesNotExist('occurrence.title')
        ->assertCanSeeTableRecords([$pendingEvent])
        ->assertTableActionExists('approve', record: $pendingEvent);
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
    $event->persons()->attach($person);
    $event->references()->attach($pendingReference);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful()
        ->assertSee('References Status')
        ->assertSee('1 needs review')
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
    $event->persons()->attach($person);
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
    $event->persons()->attach($person);

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
        'actionable_type' => (new Event)->getMorphClass(),
        'actioned_by_type' => (new User)->getMorphClass(),
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
    ]);

    $notOpenReportedEvent = Event::factory()->create([
        'title' => 'Resolved Report Event',
        'status' => 'pending',
    ]);

    $resolvedReport = $notOpenReportedEvent->reports()->create([
        'reporter_id' => $reporter->id,
        'handled_by' => $moderator->id,
        'category' => 'other',
        'description' => 'Already handled.',
        'resolution_note' => 'Reviewed and resolved.',
    ]);
    $resolvedReport->transitionStatus(Report::STATUS_RESOLVED);

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

it('runs moderation queue actions through the events moderation boundary', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $event = Event::factory()->create([
        'status' => 'pending',
    ]);

    Livewire::actingAs($moderator)
        ->test(ModerationQueue::class)
        ->callTableAction('approve', $event, data: ['note' => 'Approved from queue test.'])
        ->assertHasNoTableActionErrors();

    expect((string) $event->fresh()->status)->toBe('approved');
});

it('shows all speakers verified when every linked speaker is verified', function () {
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
    $event->persons()->attach($person);

    $this->actingAs($moderator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful()
        ->assertSee('Persons Status')
        ->assertSee('All verified')
        ->assertDontSee('needs review');
});

it('allows admin role users to moderate and auto-verifies pending related records', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('admin');

    $this->actingAs($administrator)
        ->get('/admin/moderation-queue')
        ->assertSuccessful();

    $pendingPerson = Person::factory()->create(['status' => 'pending']);
    $pendingInstitution = Institution::factory()->create(['status' => 'pending']);
    $event = Event::factory()->create([
        'status' => 'pending',
        'institution_id' => $pendingInstitution->id,
    ]);
    $event->persons()->attach($pendingPerson);

    Livewire::actingAs($administrator)
        ->test(ModerationQueue::class)
        ->callTableAction('approve', $event, data: ['note' => 'Approved by admin.'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Event approved');

    expect((string) $event->fresh()->status)->toBe('approved')
        ->and($pendingPerson->fresh()->status)->toBe('verified')
        ->and($pendingInstitution->fresh()->status)->toBe(InstitutionStatus::Verified);
});

it('requests changes and rejects events through the moderation queue with notifications', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $needsChangesEvent = Event::factory()->create(['status' => 'pending']);
    $rejectedEvent = Event::factory()->create(['status' => 'pending']);

    $component = Livewire::actingAs($moderator)->test(ModerationQueue::class);

    $component
        ->callTableAction('needs_changes', $needsChangesEvent, data: [
            'reason_code' => 'incomplete_info',
            'note' => 'Please add speaker details.',
        ])
        ->assertNotified('Changes requested');

    $component
        ->callTableAction('reject', $rejectedEvent, data: [
            'reason_code' => 'spam',
            'note' => 'This is spam.',
        ])
        ->assertNotified('Event rejected');

    expect((string) $needsChangesEvent->fresh()->status)->toBe('needs_changes')
        ->and((string) $rejectedEvent->fresh()->status)->toBe('rejected');
});

it('reviews occurrence submissions through the moderation queue with notifications', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $event = Event::factory()->create(['status' => 'approved']);
    $occurrence = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'status' => 'draft',
        'visibility' => 'private',
    ]);
    $submission = EventSubmission::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'event_session_id' => null,
        'status' => 'pending',
        'submitted_at' => now(),
    ]);

    Livewire::actingAs($moderator)
        ->test(ModerationQueue::class)
        ->call('setActiveTab', 'pending_occurrences')
        ->assertTableColumnExists('submission_kind')
        ->callTableAction('approve_occurrence', $submission->getKey(), data: ['note' => 'Looks good.'])
        ->assertNotified(__('Occurrence approved'));

    expect((string) $submission->fresh()->status)->toBe('approved');
});

it('moderates events from the view page through the events boundary', function (): void {
    Filament::setCurrentPanel('admin');

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $pendingEvent = Event::factory()->create(['status' => 'pending']);

    Livewire::actingAs($moderator)
        ->test(ViewEvent::class, ['record' => $pendingEvent->getRouteKey()])
        ->callAction('approve', ['note' => 'Approved from the view page.'])
        ->assertNotified('Event approved');

    expect((string) $pendingEvent->fresh()->status)->toBe('approved');

    $toReject = Event::factory()->create(['status' => 'pending']);

    Livewire::actingAs($moderator)
        ->test(ViewEvent::class, ['record' => $toReject->getRouteKey()])
        ->callAction('reject', ['reason_code' => 'spam', 'note' => 'Not acceptable.'])
        ->assertNotified('Event rejected');

    expect((string) $toReject->fresh()->status)->toBe('rejected');

    $toCancel = Event::factory()->create(['status' => 'approved']);

    Livewire::actingAs($moderator)
        ->test(ViewEvent::class, ['record' => $toCancel->getRouteKey()])
        ->callAction('cancel', ['note' => 'Venue closed.'])
        ->assertNotified('Event cancelled');

    expect((string) $toCancel->fresh()->status)->toBe('cancelled');
});

it('shows a danger notification when a moderation action no longer matches the event state', function (): void {
    Filament::setCurrentPanel('admin');

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $event = Event::factory()->create(['status' => 'pending']);

    $this->mock(EventModeration::class, function (MockInterface $mock): void {
        $mock->shouldReceive('approve')
            ->once()
            ->andThrow(TransitionNotFound::make('approved', 'approved', Event::class));
    });

    Livewire::actingAs($moderator)
        ->test(ViewEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('approve', ['note' => 'Stale moderation attempt.'])
        ->assertNotified(__('This event is no longer in a state that allows this action. Please refresh to see its current status.'));

    expect((string) $event->fresh()->status)->toBe('pending');
});

it('reverts a cancelled event to draft through the events boundary', function (): void {
    Filament::setCurrentPanel('admin');

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $event = Event::factory()->create([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    Livewire::actingAs($moderator)
        ->test(ViewEvent::class, ['record' => $event->getRouteKey()])
        ->assertActionVisible('revert_to_draft')
        ->callAction('revert_to_draft', ['note' => 'Bringing this back for edits.'])
        ->assertNotified('Event reverted to draft');

    expect((string) $event->fresh()->status)->toBe('draft');
});

it('does not count initial pending-event occurrence submissions in the queue badge', function (): void {
    $pendingEvent = Event::factory()->create(['status' => 'pending']);
    $approvedEvent = Event::factory()->create(['status' => 'approved']);

    $pendingOccurrence = EventOccurrence::factory()->create([
        'event_id' => $pendingEvent->getKey(),
        'status' => 'draft',
        'visibility' => 'private',
    ]);

    EventSubmission::query()->create([
        'event_id' => $pendingEvent->getKey(),
        'event_occurrence_id' => $pendingOccurrence->getKey(),
        'event_session_id' => null,
        'status' => 'pending',
        'submitted_at' => now(),
    ]);

    $reviewableOccurrence = EventOccurrence::factory()->create([
        'event_id' => $approvedEvent->getKey(),
        'status' => 'draft',
        'visibility' => 'private',
    ]);

    EventSubmission::query()->create([
        'event_id' => $approvedEvent->getKey(),
        'event_occurrence_id' => $reviewableOccurrence->getKey(),
        'event_session_id' => null,
        'status' => 'pending',
        'submitted_at' => now(),
    ]);

    ModerationQueue::flushNavigationBadgeCache();

    expect(ModerationQueue::getNavigationBadge())->toBe('2');
});
