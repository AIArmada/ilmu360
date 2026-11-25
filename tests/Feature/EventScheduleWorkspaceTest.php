<?php

declare(strict_types=1);

use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Catalog\Models\EventSession;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Events\Api\OccurrenceSubmissionReviews;
use App\Features\Events\EntryPoints\Livewire\Pages\Dashboard\Events\Schedule;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('lets a draft event creator add and edit occurrences and sessions', function (): void {
    $organizer = User::factory()->create();
    $this->actingAs($organizer);

    $event = Event::factory()->create([
        'title' => 'A Two Day Learning Programme',
        'status' => 'draft',
        'published_at' => null,
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'delivery_mode' => 'physical',
        'starts_at' => CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-10 14:00:00', 'UTC'),
    ]);

    $component = Livewire::actingAs($organizer)->test(Schedule::class, ['event' => $event]);

    $component
        ->call('openOccurrenceForm')
        ->set('occurrenceForm.title', 'Day Two')
        ->set('occurrenceForm.starts_at', '2026-10-11T20:00')
        ->set('occurrenceForm.ends_at', '2026-10-11T22:00')
        ->set('occurrenceForm.timezone', 'Asia/Kuala_Lumpur')
        ->set('occurrenceForm.capacity', '120')
        ->call('saveOccurrence')
        ->assertHasNoErrors();

    $occurrence = EventOccurrence::query()
        ->withoutOwnerScope()
        ->where('event_id', $event->getKey())
        ->where('title', 'Day Two')
        ->firstOrFail();

    expect($occurrence->starts_at->toIso8601String())->toBe('2026-10-11T12:00:00+00:00')
        ->and($occurrence->ends_at->toIso8601String())->toBe('2026-10-11T14:00:00+00:00')
        ->and($occurrence->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($occurrence->capacity)->toBe(120);

    $component
        ->call('openSessionForm', $occurrence->getKey())
        ->set('sessionForm.title', 'Opening Session')
        ->set('sessionForm.summary', 'A short opening session.')
        ->set('sessionForm.starts_at', '2026-10-11T20:30')
        ->set('sessionForm.ends_at', '2026-10-11T21:30')
        ->set('sessionForm.timezone', 'Asia/Kuala_Lumpur')
        ->call('saveSession')
        ->assertHasNoErrors();

    $session = EventSession::query()
        ->withoutOwnerScope()
        ->where('event_id', $event->getKey())
        ->where('event_occurrence_id', $occurrence->getKey())
        ->where('title', 'Opening Session')
        ->firstOrFail();

    expect($session->starts_at->toIso8601String())->toBe('2026-10-11T12:30:00+00:00')
        ->and($session->ends_at->toIso8601String())->toBe('2026-10-11T13:30:00+00:00')
        ->and($session->summary)->toBe('A short opening session.');

    $component
        ->call('editOccurrence', $occurrence->getKey())
        ->set('occurrenceForm.title', 'Day Two — Updated')
        ->call('saveOccurrence')
        ->assertHasNoErrors();

    expect($occurrence->fresh()->title)->toBe('Day Two — Updated');
});

it('shows the schedule workspace through the authenticated dashboard route', function (): void {
    $organizer = User::factory()->create();
    $this->actingAs($organizer);

    $event = Event::factory()->create([
        'title' => 'Schedule Route Event',
        'status' => 'draft',
        'published_at' => null,
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);

    $response = $this->get(route('dashboard.events.schedule', ['event' => $event]));

    $response->assertOk()
        ->assertSee('Schedule Route Event')
        ->assertSee(__('How the schedule is organized'));
});

it('stages a new occurrence from a published schedule for a different reviewer', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(EventRoleSeeder::class);
    $organizer = User::factory()->create();
    $reviewer = User::factory()->create();
    $reviewer->assignRole('moderator');
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);
    addTestMember($event, $organizer, MemberRole::Owner);

    $component = Livewire::actingAs($organizer)->test(Schedule::class, ['event' => $event]);
    $component->assertSee(__('Propose an occurrence'))
        ->assertDontSee(__('Add session'))
        ->call('openOccurrenceForm')
        ->set('occurrenceForm.title', 'Additional date')
        ->set('occurrenceForm.note', 'Requested by the venue')
        ->call('saveOccurrence')
        ->assertHasNoErrors();

    $submission = EventSubmission::query()->where('event_id', $event->getKey())->sole();
    $occurrence = EventOccurrence::query()->findOrFail($submission->event_occurrence_id);

    expect((string) $submission->status)->toBe('pending')
        ->and($submission->submitter_id)->toBe($organizer->getKey())
        ->and($submission->submission_data['notes'])->toBe('Requested by the venue')
        ->and((string) $occurrence->status)->toBe(EventOccurrence::DRAFT)
        ->and((string) $occurrence->visibility)->toBe('private');

    $component->call('editOccurrence', $occurrence->getKey())->assertForbidden();

    app(OccurrenceSubmissionReviews::class)->approve(
        (string) $submission->getKey(),
        $reviewer->getMorphClass(),
        (string) $reviewer->getKey(),
    );

    $occurrence->refresh();
    expect((string) $occurrence->status)->toBe(EventOccurrence::SCHEDULED)
        ->and((string) $occurrence->visibility)->toBe('public');
});

it('reviews proposed occurrence and session changes before changing the published schedule', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(EventRoleSeeder::class);
    $organizer = User::factory()->create();
    $reviewer = User::factory()->create();
    $reviewer->assignRole('moderator');
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'delivery_mode' => 'physical',
    ]);
    addTestMember($event, $organizer, MemberRole::Owner);
    $occurrence = $event->occurrences()->where('status', EventOccurrence::SCHEDULED)->firstOrFail();

    $component = Livewire::actingAs($organizer)->test(Schedule::class, ['event' => $event]);
    $component->call('editOccurrence', $occurrence->getKey())
        ->set('occurrenceForm.title', 'Proposed occurrence title')
        ->call('saveOccurrence')
        ->assertHasNoErrors();

    $first = EventSubmission::query()->where('event_id', $event->getKey())->sole();
    expect($occurrence->fresh()->title)->not->toBe('Proposed occurrence title');
    $reviews = app(OccurrenceSubmissionReviews::class);
    $reviews->approve((string) $first->getKey(), $reviewer->getMorphClass(), (string) $reviewer->getKey());
    expect($occurrence->fresh()->title)->toBe('Proposed occurrence title');

    $component->call('openSessionForm', $occurrence->getKey())
        ->set('sessionForm.title', 'Proposed session')
        ->set('sessionForm.note', 'Spare slot available.')
        ->call('saveSession')
        ->assertHasNoErrors();

    $second = EventSubmission::query()->where('event_id', $event->getKey())->where('status', 'pending')->sole();
    expect($occurrence->sessions()->where('title', 'Proposed session')->exists())->toBeFalse()
        ->and($second->submission_data['notes'])->toBe('Spare slot available.');
    $reviews->approve((string) $second->getKey(), $reviewer->getMorphClass(), (string) $reviewer->getKey());
    $session = $occurrence->sessions()->where('title', 'Proposed session')->sole();

    $component->call('editSession', $session->getKey())
        ->set('sessionForm.title', 'Proposed replacement')
        ->call('saveSession')
        ->assertHasNoErrors();

    $third = EventSubmission::query()->where('event_id', $event->getKey())->where('status', 'pending')->sole();
    expect($session->fresh()->title)->toBe('Proposed session');

    $session->forceFill(['title' => 'Current title'])->save();
    expect(fn () => $reviews->approve((string) $third->getKey(), $reviewer->getMorphClass(), (string) $reviewer->getKey()))
        ->toThrow(ValidationException::class);

    $reviews->reject((string) $third->getKey(), $reviewer->getMorphClass(), (string) $reviewer->getKey(), 'other', 'Schedule changed.');
    expect($session->fresh()->title)->toBe('Current title')
        ->and((string) $third->fresh()->status)->toBe('rejected');
});

it('blocks a user who cannot update the event from the schedule workspace', function (): void {
    $organizer = User::factory()->create();
    $outsider = User::factory()->create();
    $this->actingAs($organizer);

    $event = Event::factory()->create([
        'status' => 'draft',
        'published_at' => null,
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);

    $this->actingAs($outsider)
        ->get(route('dashboard.events.schedule', ['event' => $event]))
        ->assertForbidden();
});
