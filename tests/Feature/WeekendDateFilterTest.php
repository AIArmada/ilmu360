<?php

use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Livewire\Pages\Events\Index as EventsIndex;
use App\Livewire\Pages\Persons\Show as PersonShow;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('resolves this weekend to saturday and sunday when today is sunday', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 9, 20, 10, 0, 0, 'Asia/Kuala_Lumpur'));

    try {
        Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->test(EventsIndex::class)
            ->set('filterData.date_shortcut', 'this_weekend')
            ->assertSet('starts_after', '2026-09-19')
            ->assertSet('starts_before', '2026-09-20');
    } finally {
        Carbon::setTestNow();
    }
});

it('resolves this weekend to saturday and sunday when today is saturday', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 9, 19, 10, 0, 0, 'Asia/Kuala_Lumpur'));

    try {
        Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->test(EventsIndex::class)
            ->set('filterData.date_shortcut', 'this_weekend')
            ->assertSet('starts_after', '2026-09-19')
            ->assertSet('starts_before', '2026-09-20');
    } finally {
        Carbon::setTestNow();
    }
});

it('resolves this weekend to the coming saturday and sunday on weekdays', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 10, 0, 0, 'Asia/Kuala_Lumpur'));

    try {
        Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->test(EventsIndex::class)
            ->set('filterData.date_shortcut', 'this_weekend')
            ->assertSet('starts_after', '2026-09-19')
            ->assertSet('starts_before', '2026-09-20');
    } finally {
        Carbon::setTestNow();
    }
});

it('excludes monday events from this weekend on institution pages viewed on sunday', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 9, 20, 10, 0, 0, 'Asia/Kuala_Lumpur'));

    $institution = Institution::factory()->create(['status' => 'verified']);

    Event::factory()->for($institution)->create([
        'title' => 'Kuliah Ahad Petang',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => Carbon::create(2026, 9, 20, 15, 0, 0, 'Asia/Kuala_Lumpur'),
    ]);
    Event::factory()->for($institution)->create([
        'title' => 'Kuliah Isnin Pagi',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => Carbon::create(2026, 9, 21, 9, 0, 0, 'Asia/Kuala_Lumpur'),
    ]);

    try {
        Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->test('pages.institutions.show', ['institution' => $institution])
            ->set('upcomingDateFilter', 'this_weekend')
            ->assertSee('Kuliah Ahad Petang')
            ->assertDontSee('Kuliah Isnin Pagi');
    } finally {
        Carbon::setTestNow();
    }
});

it('excludes monday events from this weekend on person pages viewed on sunday', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 9, 20, 10, 0, 0, 'Asia/Kuala_Lumpur'));

    $person = Person::factory()->create(['status' => 'verified']);

    $sundayEvent = Event::factory()->create([
        'title' => 'Ceramah Ahad Petang',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => Carbon::create(2026, 9, 20, 15, 0, 0, 'Asia/Kuala_Lumpur'),
    ]);
    $mondayEvent = Event::factory()->create([
        'title' => 'Ceramah Isnin Pagi',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => Carbon::create(2026, 9, 21, 9, 0, 0, 'Asia/Kuala_Lumpur'),
    ]);

    foreach ([$sundayEvent, $mondayEvent] as $event) {
        EventKeyPerson::query()->create([
            'event_id' => $event->getKey(),
            'involveable_type' => 'person',
            'involveable_id' => $person->getKey(),
            'role_code' => EventKeyPersonRole::Speaker->value,
            'sort_order' => 1,
            'visibility' => 'public',
        ]);
    }

    try {
        Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->test(PersonShow::class, ['person' => $person])
            ->set('upcomingDateFilter', 'this_weekend')
            ->assertSee('Ceramah Ahad Petang')
            ->assertDontSee('Ceramah Isnin Pagi');
    } finally {
        Carbon::setTestNow();
    }
});
