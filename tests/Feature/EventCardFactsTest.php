<?php

use App\Enums\EventFormat;
use App\Models\Event;
use App\Models\Institution;

it('shows the actual approval state independently of schedule notices', function (string $status, ?string $approvalLabel) {
    $event = Event::factory()->create(['status' => $status]);

    $view = $this->blade(
        '<x-events.card :event="$event" status-label="Schedule updated" />',
        ['event' => $event],
    );

    $view->assertSee('Schedule updated');

    if ($approvalLabel === null) {
        $view->assertDontSee('data-testid="event-card-approval-badge"', false);
    } else {
        $view->assertSee(__($approvalLabel));
    }
})->with([
    'approved' => ['approved', 'Diluluskan'],
    'pending' => ['pending', 'Menunggu Kelulusan'],
    'cancelled' => ['cancelled', null],
]);

it('shows online attendance without a physical venue on online-only cards', function () {
    $event = Event::factory()->create(['delivery_mode' => EventFormat::Online]);

    $this->blade(
        '<x-events.card :event="$event" location-primary="Physical venue" location-secondary="Shah Alam, Selangor" />',
        ['event' => $event],
    )
        ->assertSee(__('Online'))
        ->assertDontSee('Physical venue')
        ->assertDontSee('Shah Alam, Selangor');
});

it('shows both venue and online availability on hybrid cards', function () {
    $event = Event::factory()->create(['delivery_mode' => EventFormat::Hybrid]);

    $this->blade(
        '<x-events.card :event="$event" location-primary="Physical venue" location-secondary="Shah Alam, Selangor" />',
        ['event' => $event],
    )
        ->assertSee(__('Hybrid'))
        ->assertSee('Physical venue')
        ->assertSee('Shah Alam, Selangor')
        ->assertSee(__('Turut tersedia dalam talian'));
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
