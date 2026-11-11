<?php

use App\Shared\Events\Enums\EventFormat;
use App\Models\Event;
use App\Models\Institution;

it('shows approval only for approved events independently of schedule notices', function (string $status, ?string $approvalLabel) {
    $event = Event::factory()->create(['status' => $status]);

    $view = $this->blade(
        '<x-events.card :event="$event" status-label="Schedule updated" />',
        ['event' => $event],
    );

    $view->assertSee('Schedule updated')
        ->assertSee('data-save-icon="event"', false)
        ->assertDontSee('aria-label="'.__('Kongsi').'"', false)
        ->assertDontSee('data-signal-event="share.event_clicked"', false)
        ->assertDontSee('data-signal-control="share_event"', false);

    if ($status === 'cancelled') {
        $view->assertDontSee('data-going-icon="event"', false);
    } else {
        $view->assertSee('data-going-icon="event"', false)
            ->assertSee('data-going-state="not-going"', false)
            ->assertSee(__('Akan Hadir'));
    }

    if ($approvalLabel === null) {
        $view->assertDontSee('data-testid="event-card-approval-badge"', false);
    } else {
        $view->assertSee(__($approvalLabel));
    }
})->with([
    'approved' => ['approved', 'Diluluskan'],
    'pending' => ['pending', null],
    'cancelled' => ['cancelled', null],
]);

it('shows online attendance without a physical venue on online-only cards', function () {
    $event = Event::factory()->create(['delivery_mode' => EventFormat::Online]);

    $this->blade(
        '<x-events.card :event="$event" location-primary="Physical venue" location-secondary="Shah Alam, Selangor" />',
        ['event' => $event],
    )
        ->assertSee(__('Online'))
        ->assertDontSee('data-testid="event-card-location"', false)
        ->assertDontSee('Physical venue')
        ->assertDontSee('Shah Alam, Selangor');
});

it('shows both venue and online availability on hybrid cards', function () {
    $event = Event::factory()->create(['delivery_mode' => EventFormat::Hybrid]);

    $this->blade(
        '<x-events.card :event="$event" location-primary="Physical venue" location-secondary="Shah Alam, Selangor" />',
        ['event' => $event],
    )
        ->assertSee('data-testid="event-card-format-badge"', false)
        ->assertSee('data-testid="event-card-format-online-badge"', false)
        ->assertSee(__('Physical'))
        ->assertSee(__('Online'))
        ->assertSee('Physical venue')
        ->assertSee('Shah Alam, Selangor')
        ->assertDontSee(__('Turut tersedia dalam talian'));
});

it('removes repeated city and state names from public event card locations', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, [
        'country_code' => 'MY',
        'city' => 'Perlis',
        'state' => 'Perlis',
    ]);
    Event::factory()->create([
        'title' => 'Unique location event',
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
        'delivery_mode' => EventFormat::Physical,
    ]);

    $this->get(route('events.index', ['search' => 'Unique location event']))
        ->assertSee('Perlis')
        ->assertDontSee('Perlis, Perlis');
});

it('reflects going state on upcoming cards and hides the action for past events', function () {
    $upcoming = Event::factory()->create([
        'status' => 'approved',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
    ]);

    $this->blade('<x-events.card :event="$upcoming" />', ['upcoming' => $upcoming])
        ->assertSee('data-going-icon="event"', false)
        ->assertSee('data-going-state="not-going"', false)
        ->assertSee('aria-label="'.__('Akan Hadir').'"', false);

    $this->blade('<x-events.card :event="$upcoming" :is-going="true" />', ['upcoming' => $upcoming])
        ->assertSee('data-going-state="going"', false)
        ->assertSee('aria-label="'.__('Hadir').'"', false);

    $past = Event::factory()->create([
        'status' => 'approved',
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(3)->addHours(2),
    ]);

    $this->blade('<x-events.card :event="$past" />', ['past' => $past])
        ->assertDontSee('data-going-icon="event"', false);
});

it('renders the going action on public event index cards', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory()->create([
        'title' => 'Majlis Akan Hadir Card',
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'delivery_mode' => EventFormat::Physical,
    ]);

    $this->get(route('events.index', ['search' => 'Majlis Akan Hadir Card']))
        ->assertSuccessful()
        ->assertSee('data-going-icon="event"', false)
        ->assertSee('data-going-state="not-going"', false)
        ->assertSee('data-save-icon="event"', false)
        ->assertDontSee('data-signal-event="share.event_clicked"', false)
        ->assertSee(__('Akan Hadir'));
});
