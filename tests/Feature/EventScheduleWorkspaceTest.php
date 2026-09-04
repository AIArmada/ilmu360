<?php

declare(strict_types=1);

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Livewire\Pages\Dashboard\Events\Schedule;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('lets a draft event creator add and edit occurrences and sessions', function (): void {
    $organizer = User::factory()->create();
    $this->actingAs($organizer);

    $event = Event::factory()->create([
        'title' => 'A Two Day Learning Programme',
        'status' => 'draft',
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
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);

    $response = $this->get(route('dashboard.events.schedule', ['event' => $event]));

    $response->assertOk()
        ->assertSee('Schedule Route Event')
        ->assertSee(__('How the schedule is organized'));
});

it('blocks a user who cannot update the event from the schedule workspace', function (): void {
    $organizer = User::factory()->create();
    $outsider = User::factory()->create();
    $this->actingAs($organizer);

    $event = Event::factory()->create([
        'status' => 'draft',
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);

    $this->actingAs($outsider)
        ->get(route('dashboard.events.schedule', ['event' => $event]))
        ->assertForbidden();
});
