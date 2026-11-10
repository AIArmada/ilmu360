<?php

use App\Features\Directory\Api\ReferenceWorkflow;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Person;
use App\Models\Reference;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('displays upcoming events and links to the filtered past events for the reference', function () {
    $reference = Reference::factory()->create();

    $upcomingEvent = Event::factory()->create([
        'title' => 'Kuliah Maghrib Akan Datang',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDays(3),
    ]);
    $upcomingEvent->references()->attach($reference->id);

    $pastEvent = Event::factory()->create([
        'title' => 'Kuliah Subuh Lalu',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subDays(4),
        'starts_at' => now()->subDays(3),
    ]);
    $pastEvent->references()->attach($reference->id);

    $unrelatedEvent = Event::factory()->create([
        'title' => 'Kuliah Rujukan Lain',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDays(3),
    ]);
    $unrelatedEvent->references()->attach(Reference::factory()->create()->id);

    $this->get(route('references.show', $reference))
        ->assertSuccessful()
        ->assertSee('Majlis Akan Datang')
        ->assertSee('Kuliah Maghrib Akan Datang')
        ->assertDontSee('Kuliah Subuh Lalu')
        ->assertDontSee('Kuliah Rujukan Lain')
        ->assertSeeHtml('data-testid="reference-past-events-cta"')
        ->assertSee(route('events.index', [
            'reference_ids' => [(string) $reference->getKey()],
            'time_scope' => 'past',
            'filters_panel' => 'closed',
        ]));
});

it('does not repeat the reference title inside its majlis cards', function () {
    $reference = Reference::factory()->create([
        'title' => 'Al-Fiqh Al-Manhaji',
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'title' => 'Kuliah Fiqh Al-Manhaji',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDays(3),
    ]);
    $event->references()->attach($reference->id);

    $html = (string) $this->get(route('references.show', $reference))
        ->assertSuccessful()
        ->getContent();

    preg_match('/<article[^>]*wire:key="upcoming-occurrence-'.preg_quote((string) $event->occurrences()->firstOrFail()->getKey(), '/').'"[^>]*>.*?<\/article>/s', $html, $matches);

    expect($matches[0] ?? null)
        ->not->toBeNull()
        ->toContain('Kuliah Fiqh Al-Manhaji')
        ->not->toContain('Al-Fiqh Al-Manhaji');
});

it('filters upcoming reference events by friendly date ranges', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 30, 10, 0, 0, 'UTC'));

    $reference = Reference::factory()->create();

    $todayEvent = Event::factory()->create([
        'title' => 'Majlis Hari Ini',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => Carbon::create(2026, 7, 29, 10, 0, 0, 'UTC'),
        'starts_at' => Carbon::create(2026, 7, 30, 12, 0, 0, 'UTC'),
    ]);
    $todayEvent->references()->attach($reference->id);

    $nextMonthEvent = Event::factory()->create([
        'title' => 'Majlis Bulan Depan',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => Carbon::create(2026, 7, 29, 10, 0, 0, 'UTC'),
        'starts_at' => Carbon::create(2026, 8, 3, 12, 0, 0, 'UTC'),
    ]);
    $nextMonthEvent->references()->attach($reference->id);

    try {
        Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->test('pages.references.show', ['reference' => $reference])
            ->set('upcomingDateFilter', 'today')
            ->assertSee('Majlis Hari Ini')
            ->assertDontSee('Majlis Bulan Depan')
            ->set('upcomingDateFilter', 'next_month')
            ->assertSee('Majlis Bulan Depan')
            ->assertDontSee('Majlis Hari Ini')
            ->set('upcomingDateFilter', 'tomorrow')
            ->assertSee('Tiada majlis untuk tempoh ini')
            ->assertSee('Tunjukkan semua majlis');
    } finally {
        Carbon::setTestNow();
    }
});

it('does not show private events on the reference page', function () {
    $reference = Reference::factory()->create();

    $privateEvent = Event::factory()->create([
        'title' => 'Kuliah Tertutup Dalaman',
        'status' => 'approved',
        'visibility' => EventVisibility::Private,
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDays(3),
    ]);
    $privateEvent->references()->attach($reference->id);

    $this->get(route('references.show', $reference))
        ->assertSuccessful()
        ->assertDontSee('Kuliah Tertutup Dalaman');
});

it('shows pending public events without a pending status badge or notice', function () {
    $reference = Reference::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'title' => 'Kuliah Rujukan Menanti',
        'status' => 'pending',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subMinute(),
        'starts_at' => now()->addDay(),
    ]);
    $event->references()->attach($reference->id);

    $this->get(route('references.show', $reference))
        ->assertSuccessful()
        ->assertSee('Kuliah Rujukan Menanti')
        ->assertDontSee('Menunggu Kelulusan')
        ->assertDontSee('Semak lencana status pada setiap majlis sebelum hadir.');
});

it('renders each reference author on its own line in the hero', function () {
    $firstAuthor = Person::factory()->create(['name' => 'Imam al-Nawawi']);
    $secondAuthor = Person::factory()->create(['name' => 'Ibn Hajar al-Asqalani']);

    $referenceId = app(ReferenceWorkflow::class)->saveFromAdmin([
        'title' => 'Authored Work',
        'type' => 'book',
        'record_kind' => 'work',
        'author_ids' => [(string) $firstAuthor->getKey(), (string) $secondAuthor->getKey()],
        'status' => 'verified',
    ]);
    $reference = Reference::query()->findOrFail($referenceId);

    $this->get(route('references.show', $reference))
        ->assertSuccessful()
        ->assertSee('<p>Imam al-Nawawi</p>', false)
        ->assertSee('<p>Ibn Hajar al-Asqalani</p>', false)
        ->assertDontSee('Imam al-Nawawi · Ibn Hajar al-Asqalani');
});

it('loads more upcoming events via Livewire', function () {
    $reference = Reference::factory()->create();

    foreach (range(1, 7) as $index) {
        $event = Event::factory()->create([
            'title' => "Kuliah Siri {$index}",
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'published_at' => now()->subMinute(),
            'starts_at' => now()->addDays($index),
        ]);
        $event->references()->attach($reference->id);
    }

    Livewire::test('pages.references.show', ['reference' => $reference])
        ->assertSee('Lihat Lagi')
        ->assertDontSee('Kuliah Siri 7')
        ->call('loadMoreUpcoming')
        ->assertSee('Kuliah Siri 7');
});
