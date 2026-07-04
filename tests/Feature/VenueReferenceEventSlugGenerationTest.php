<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\References\GenerateReferenceSlugAction;
use App\Actions\Venues\GenerateVenueSlugAction;
use App\Console\Commands\QueueBackfillVenueSlugs;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Filament\Ahli\Resources\Events\Pages\EditEvent as AhliEditEvent;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\References\Pages\CreateReference;
use App\Filament\Resources\Venues\Pages\CreateVenue;
use App\Forms\VenueFormSchema;
use App\Jobs\BackfillEventSlugs;
use App\Jobs\BackfillReferenceSlugs;
use App\Jobs\BackfillVenueSlugs;
use App\Livewire\Pages\Dashboard\Events\CreateAdvanced;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;
use App\Services\EventKeyPersonSyncService;
use App\Support\Cache\PublicListingsCache;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('generates geographic slugs for venue quick-create flows', function () {
    $geography = createVenueSlugGeography();

    $venueId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Sultan Salahudin Abdul Aziz Shah',
        'type' => 'dewan',
        'address' => venueGeographyAddressPayload($geography),
    ]);

    $venue = Venue::query()->findOrFail($venueId);

    expect($venue->slug)->toBe('dewan-sultan-salahudin-abdul-aziz-shah-shah-alam-selangor-my');
});

it('adds duplicate numbering only when the same venue name reuses the same subdistrict', function () {
    $primaryGeography = createVenueSlugGeography();
    $secondaryGeography = createVenueSlugGeography(subdistrictName: 'Subang Jaya');

    $firstId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Sultan Salahudin Abdul Aziz Shah',
        'type' => 'dewan',
        'address' => venueGeographyAddressPayload($primaryGeography),
    ]);

    $secondId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Sultan Salahudin Abdul Aziz Shah',
        'type' => 'dewan',
        'address' => venueGeographyAddressPayload($primaryGeography),
    ]);

    $thirdId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Sultan Salahudin Abdul Aziz Shah',
        'type' => 'dewan',
        'address' => venueGeographyAddressPayload($secondaryGeography),
    ]);

    expect(Venue::query()->findOrFail($firstId)->slug)->toBe('dewan-sultan-salahudin-abdul-aziz-shah-shah-alam-selangor-my')
        ->and(Venue::query()->findOrFail($secondId)->slug)->toBe('dewan-sultan-salahudin-abdul-aziz-shah-2-shah-alam-selangor-my')
        ->and(Venue::query()->findOrFail($thirdId)->slug)->toBe('dewan-sultan-salahudin-abdul-aziz-shah-subang-jaya-selangor-my');
});

it('uses the generated geographic slug when admins create venues in filament', function () {
    $administrator = createSlugAdminUser();
    $geography = createVenueSlugGeography();

    $original = config('contacting.features.owner.enabled');
    config(['contacting.features.owner.enabled' => false]);

    try {
        Livewire::actingAs($administrator)
            ->test(CreateVenue::class)
            ->fillForm([
                'name' => 'Dewan Sultan Salahudin Abdul Aziz Shah',
                'slug' => 'temporary-admin-slug',
                'type' => 'dewan',
                'status' => 'verified',
                'is_active' => true,
                'facilities' => [],
                'contacts' => [],
                'socialMedia' => [],
                'address' => venueGeographyAddressPayload($geography),
            ])
            ->call('create')
            ->assertHasNoErrors();
    } finally {
        config(['contacting.features.owner.enabled' => $original]);
    }

    $venue = Venue::query()
        ->where('name', 'Dewan Sultan Salahudin Abdul Aziz Shah')
        ->firstOrFail();

    expect($venue->slug)->toBe('dewan-sultan-salahudin-abdul-aziz-shah-shah-alam-selangor-my');
});

it('recomputes venue slugs when the venue locality changes', function () {
    $primaryGeography = createVenueSlugGeography();
    $secondaryGeography = createVenueSlugGeography(subdistrictName: 'Subang Jaya');

    $venueId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Sultan Salahudin Abdul Aziz Shah',
        'type' => 'dewan',
        'address' => venueGeographyAddressPayload($primaryGeography),
    ]);

    $venue = Venue::query()->findOrFail($venueId);

    $venue->addressModel?->update([
        'admin_area_3_id' => $secondaryGeography['subdistrict']->getKey(),
        'admin_area_2_id' => $secondaryGeography['district']->getKey(),
        'admin_area_1_id' => $secondaryGeography['state']->getKey(),
        'country_id' => $secondaryGeography['country']->getKey(),
        'city' => $secondaryGeography['subdistrict']->name,
    ]);

    app(GenerateVenueSlugAction::class)->syncVenueSlug($venue->fresh());

    expect($venue->fresh()?->slug)->toBe('dewan-sultan-salahudin-abdul-aziz-shah-subang-jaya-selangor-my');
});

it('backfills existing venue slugs through the queued job logic', function () {
    $geography = createVenueSlugGeography();

    $first = createVenueForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000021',
        name: 'Dewan Sultan Salahudin Abdul Aziz Shah',
        slug: 'legacy-venue-1',
        geography: $geography,
    );

    $second = createVenueForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000022',
        name: 'Dewan Sultan Salahudin Abdul Aziz Shah',
        slug: 'legacy-venue-2',
        geography: $geography,
    );

    app(BackfillVenueSlugs::class)->handle(app(GenerateVenueSlugAction::class));

    expect($first->fresh()?->slug)->toBe('dewan-sultan-salahudin-abdul-aziz-shah-shah-alam-selangor-my')
        ->and($second->fresh()?->slug)->toBe('dewan-sultan-salahudin-abdul-aziz-shah-2-shah-alam-selangor-my');
});

it('queues the venue slug backfill command in a single batch', function () {
    $geography = createVenueSlugGeography();

    $first = createVenueForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000023',
        name: 'Dewan Integrasi 1',
        slug: 'legacy-venue-3',
        geography: $geography,
    );

    $second = createVenueForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000024',
        name: 'Dewan Integrasi 2',
        slug: 'legacy-venue-4',
        geography: $geography,
    );

    Bus::fake();

    $this->artisan('venues:queue-slug-backfill')
        ->expectsOutput('Queued venue slug backfill batch with 1 jobs for 2 venues.')
        ->assertSuccessful();

    Bus::assertBatched(function (PendingBatch $batch) use ($first, $second): bool {
        if ($batch->name !== 'venue-slug-backfill' || $batch->jobs->count() !== 1) {
            return false;
        }

        $job = $batch->jobs->first();

        return $job instanceof BackfillVenueSlugs
            && $job->venueIds === [(string) $first->getKey(), (string) $second->getKey()];
    });
});

it('does not queue overlapping venue slug backfill batches', function () {
    Bus::fake();

    $lock = Cache::lock(QueueBackfillVenueSlugs::LOCK_KEY, 3600);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('venues:queue-slug-backfill')
            ->expectsOutput('Venue slug backfill is already queued or running.')
            ->assertSuccessful();

        Bus::assertNothingBatched();
    } finally {
        $lock->forceRelease();
    }
});

it('splits venue slug backfill batches by chunk size without overlapping ids', function () {
    Bus::fake();

    $totalVenues = QueueBackfillVenueSlugs::CHUNK_SIZE + 1;
    $createdIds = collect(range(1, $totalVenues))
        ->map(function (int $index): string {
            $venue = Venue::unguarded(fn () => Venue::query()->create([
                'name' => "Venue {$index}",
                'slug' => "legacy-venue-{$index}",
                'type' => 'dewan',
                'status' => 'verified',
                'is_active' => true,
                'visibility' => 'public',
            ]));

            return (string) $venue->getKey();
        })
        ->sort()
        ->values();

    $this->artisan('venues:queue-slug-backfill')
        ->expectsOutput("Queued venue slug backfill batch with 2 jobs for {$totalVenues} venues.")
        ->assertSuccessful();

    Bus::assertBatched(function (PendingBatch $batch) use ($createdIds, $totalVenues): bool {
        if ($batch->name !== 'venue-slug-backfill' || $batch->jobs->count() !== 2) {
            return false;
        }

        $queuedIds = collect($batch->jobs)
            ->flatMap(fn (mixed $job): array => $job instanceof BackfillVenueSlugs ? $job->venueIds : [])
            ->sort()
            ->values();

        $firstJob = $batch->jobs[0] ?? null;
        $secondJob = $batch->jobs[1] ?? null;

        if (! $firstJob instanceof BackfillVenueSlugs || ! $secondJob instanceof BackfillVenueSlugs) {
            return false;
        }

        return $queuedIds->all() === $createdIds->all()
            && count($firstJob->venueIds) === QueueBackfillVenueSlugs::CHUNK_SIZE
            && count($secondJob->venueIds) === 1
            && count(array_intersect($firstJob->venueIds, $secondJob->venueIds)) === 0
            && $queuedIds->count() === $totalVenues;
    });
});

it('only backfills the venues assigned to a chunk job', function () {
    $geography = createVenueSlugGeography();

    $first = createVenueForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000025',
        name: 'Dewan Subset 1',
        slug: 'legacy-subset-1',
        geography: $geography,
    );

    $second = createVenueForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000026',
        name: 'Dewan Subset 2',
        slug: 'legacy-subset-2',
        geography: $geography,
    );

    $third = createVenueForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000027',
        name: 'Dewan Subset 3',
        slug: 'legacy-subset-3',
        geography: $geography,
    );

    new BackfillVenueSlugs([(string) $first->getKey(), (string) $second->getKey()])
        ->handle(app(GenerateVenueSlugAction::class));

    expect($first->fresh()?->slug)->not->toBe('legacy-subset-1')
        ->and($second->fresh()?->slug)->not->toBe('legacy-subset-2')
        ->and($third->fresh()?->slug)->toBe('legacy-subset-3');
});

it('generates sequential slugs for references with duplicate titles', function () {
    $first = Reference::query()->create([
        'title' => 'Riyadhus Solihin',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $second = Reference::query()->create([
        'title' => 'Riyadhus Solihin',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]);

    expect($first->slug)->toBe('riyadhus-solihin')
        ->and($second->slug)->toBe('riyadhus-solihin-2');
});

it('recomputes reference slugs when the title changes directly', function () {
    $reference = Reference::query()->create([
        'title' => 'Riyadhus Solihin',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $reference->update([
        'title' => 'Syarah Riyadhus Solihin',
    ]);

    expect($reference->fresh()?->slug)->toBe('syarah-riyadhus-solihin');
});

it('renumbers remaining reference duplicates when a peer is renamed out of the group', function () {
    $first = Reference::query()->create([
        'title' => 'Bulughul Maram',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $second = Reference::query()->create([
        'title' => 'Bulughul Maram',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]);

    expect($second->slug)->toBe('bulughul-maram-2');

    $first->update([
        'title' => 'Bulughul Maram Asal',
    ]);

    expect($first->fresh()?->slug)->toBe('bulughul-maram-asal')
        ->and($second->fresh()?->slug)->toBe('bulughul-maram');
});

it('preserves the existing duplicate reference sequence when regenerating a canonical slug', function () {
    $first = Reference::query()->create([
        'title' => 'Adab Mufrad',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $second = Reference::query()->create([
        'title' => 'Adab Mufrad',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $didChange = app(GenerateReferenceSlugAction::class)->syncReferenceSlug($second);

    expect($first->slug)->toBe('adab-mufrad')
        ->and($second->fresh()?->slug)->toBe('adab-mufrad-2')
        ->and($didChange)->toBeFalse();
});

it('uses the generated sequential slug when admins create references in filament', function () {
    $administrator = createSlugAdminUser();

    Livewire::actingAs($administrator)
        ->test(CreateReference::class)
        ->fillForm([
            'title' => 'Bulughul Maram',
            'type' => 'book',
            'status' => 'verified',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoErrors();

    $reference = Reference::query()
        ->where('title', 'Bulughul Maram')
        ->firstOrFail();

    expect($reference->slug)->toBe('bulughul-maram');
});

it('backfills existing reference slugs through the queued job logic', function () {
    $first = Reference::unguarded(fn () => Reference::query()->create([
        'id' => '00000000-0000-0000-0000-000000000031',
        'title' => 'Ihya Ulumiddin',
        'slug' => 'legacy-reference-1',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]));

    $second = Reference::unguarded(fn () => Reference::query()->create([
        'id' => '00000000-0000-0000-0000-000000000032',
        'title' => 'Ihya Ulumiddin',
        'slug' => 'legacy-reference-2',
        'type' => 'book',
        'status' => 'verified',
        'is_active' => true,
    ]));

    app(BackfillReferenceSlugs::class)->handle(
        app(GenerateReferenceSlugAction::class),
        app(PublicListingsCache::class),
    );

    expect($first->fresh()?->slug)->toBe('ihya-ulumiddin')
        ->and($second->fresh()?->slug)->toBe('ihya-ulumiddin-2');
});

it('queues the reference slug backfill command', function () {
    Queue::fake();

    $this->artisan('references:queue-slug-backfill')
        ->expectsOutput('Queued reference slug backfill job.')
        ->assertSuccessful();

    Queue::assertPushed(BackfillReferenceSlugs::class);
});

it('generates dated event slugs for public submit-event flows', function () {
    fakePrayerTimesApi();

    $country = createDefaultCountry();
    $institution = Institution::factory()->create(['status' => 'verified', 'is_active' => true]);
    $eventDate = now()->addDays(5)->toDateString();
    $expectedSuffix = Carbon::parse($eventDate, 'Asia/Kuala_Lumpur')->format('j-n-y');

    setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        [
            'title' => 'Majlis Iftar Perdana',
            'description' => 'Majlis berbuka puasa bersama komuniti.',
            'event_date' => $eventDate,
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'event_type' => [EventType::Other->value],
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'languages' => [101],
            'submission_country_id' => (string) $country->getKey(),
            'primary_organizer_id' => $institution->id,
            'submitter_name' => 'Slug Submitter',
            'submitter_email' => 'slug-submit@example.test',
        ],
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Majlis Iftar Perdana')->firstOrFail();

    expect($event->slug)->toBe("majlis-iftar-perdana-{$expectedSuffix}");
});

it('adds duplicate numbering for submit-event slugs when the title and date match exactly', function () {
    fakePrayerTimesApi();

    $country = createDefaultCountry();
    $institution = Institution::factory()->create(['status' => 'verified', 'is_active' => true]);
    $eventDate = now()->addDays(6)->toDateString();
    $expectedSuffix = Carbon::parse($eventDate, 'Asia/Kuala_Lumpur')->format('j-n-y');

    foreach ([
        'duplicate-one@example.test',
        'duplicate-two@example.test',
    ] as $email) {
        setSubmitEventFormState(
            Livewire::test('pages.submit-event.create'),
            [
                'title' => 'Majlis Iftar Perdana',
                'description' => 'Majlis berbuka puasa bersama komuniti.',
                'event_date' => $eventDate,
                'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
                'event_type' => [EventType::Other->value],
                'event_format' => EventFormat::Physical->value,
                'visibility' => EventVisibility::Public->value,
                'gender' => EventGenderRestriction::All->value,
                'age_group' => [EventAgeGroup::AllAges->value],
                'languages' => [101],
                'submission_country_id' => (string) $country->getKey(),
                'primary_organizer_id' => $institution->id,
                'submitter_name' => 'Slug Submitter',
                'submitter_email' => $email,
            ],
        )
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('submit-event.success'));
    }

    $events = Event::query()
        ->where('title', 'Majlis Iftar Perdana')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->slug)->toBe("majlis-iftar-perdana-{$expectedSuffix}")
        ->and($events[1]->slug)->toBe("majlis-iftar-perdana-2-{$expectedSuffix}");
});

it('uses the generated dated slug when admins create events in filament', function () {
    $administrator = createSlugAdminUser();
    $institution = Institution::factory()->create(['status' => 'verified', 'is_active' => true]);
    $eventDate = now()->addDays(7)->toDateString();
    $expectedSuffix = Carbon::parse($eventDate, 'Asia/Kuala_Lumpur')->format('j-n-y');

    Livewire::actingAs($administrator)
        ->test(CreateEvent::class)
        ->fillForm([
            'title' => 'Forum Ramadan Pentadbiran',
            'slug' => 'temporary-admin-event-slug',
            'event_date' => $eventDate,
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'timezone' => 'Asia/Kuala_Lumpur',
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'languages' => [101],
            'institution_id' => $institution->id,
            'references' => [],
            'series' => [],
            'speakers' => [],
            'other_key_people' => [],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $event = Event::query()
        ->where('title', 'Forum Ramadan Pentadbiran')
        ->firstOrFail();

    expect($event->slug)->toBe("forum-ramadan-pentadbiran-{$expectedSuffix}");
});

it('uses the generated speaker-aware slug when admins create events with speakers in filament', function () {
    $administrator = createSlugAdminUser();
    $eventDate = '2026-04-12';
    $expectedSuffix = Carbon::parse($eventDate, 'Asia/Kuala_Lumpur')->format('j-n-y');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $firstSpeaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $secondSpeaker = Speaker::factory()->create([
        'name' => 'Dr Ali',
        'slug' => 'dr-ali',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Livewire::actingAs($administrator)
        ->test(CreateEvent::class)
        ->fillForm([
            'title' => 'Forum Ramadan Pentadbiran Dengan Penceramah',
            'slug' => 'temporary-admin-event-slug',
            'event_date' => $eventDate,
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'timezone' => 'Asia/Kuala_Lumpur',
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'languages' => [101],
            'institution_id' => $institution->id,
            'references' => [],
            'series' => [],
            'speakers' => [$firstSpeaker->id, $secondSpeaker->id],
            'other_key_people' => [],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $event = Event::query()
        ->where('title', 'Forum Ramadan Pentadbiran Dengan Penceramah')
        ->firstOrFail();

    expect($event->slug)->toBe(sprintf(
        'forum-ramadan-pentadbiran-dengan-penceramah-%s-%s-%s',
        $firstSpeaker->fresh()?->slug,
        $secondSpeaker->fresh()?->slug,
        $expectedSuffix,
    ));
});

it('includes ordered speaker slugs in event slugs when an event has speakers', function () {
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $firstSpeaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $secondSpeaker = Speaker::factory()->create([
        'name' => 'Dr Ali',
        'slug' => 'dr-ali',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $event = createEventForTest([
        'title' => 'Forum Ramadan Pentadbiran',
        'slug' => 'legacy-event-slug',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    OwnerContext::withOwner(null, fn () => app(EventKeyPersonSyncService::class)->sync($event, [$firstSpeaker->id, $secondSpeaker->id]));

    expect($event->fresh()?->slug)->toBe(sprintf(
        'forum-ramadan-pentadbiran-%s-%s-%s',
        $firstSpeaker->fresh()?->slug,
        $secondSpeaker->fresh()?->slug,
        $expectedSuffix,
    ));
});

it('regenerates the canonical dated slug when admins edit events in filament', function () {
    $administrator = createSlugAdminUser();
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $event = createEventForTest([
        'title' => 'Forum Ramadan Pentadbiran',
        'slug' => 'legacy-event-slug',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    Livewire::actingAs($administrator)
        ->test(EditEvent::class, ['record' => $event->id])
        ->fillForm([
            'title' => 'Forum Ramadan Dikemas Kini',
            'slug' => 'manually-tampered-slug',
            'event_date' => '2026-04-12',
            'event_format' => EventFormat::Physical->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '20:00',
            'end_time' => '22:00',
            'timezone' => 'Asia/Kuala_Lumpur',
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()?->slug)->toBe("forum-ramadan-dikemas-kini-{$expectedSuffix}");
});

it('regenerates the canonical dated slug when ahli members edit events in filament', function () {
    $member = createSlugAdminUser();
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-13', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $event = createEventForTest([
        'user_id' => $member->id,
        'title' => 'Forum Ahli Ramadan',
        'slug' => 'legacy-ahli-event-slug',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    Livewire::actingAs($member)
        ->test(AhliEditEvent::class, ['record' => $event->id])
        ->fillForm([
            'title' => 'Forum Ahli Dikemas Kini',
            'slug' => 'manually-tampered-ahli-slug',
            'event_date' => '2026-04-13',
            'event_format' => EventFormat::Physical->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '20:00',
            'end_time' => '22:00',
            'timezone' => 'Asia/Kuala_Lumpur',
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()?->slug)->toBe("forum-ahli-dikemas-kini-{$expectedSuffix}");
});

it('previews the speaker-aware slug when admins edit event speakers', function () {
    $administrator = createSlugAdminUser();
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $event = createEventForTest([
        'title' => 'Forum Ramadan Edit Preview',
        'slug' => 'legacy-event-slug',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    Livewire::actingAs($administrator)
        ->test(EditEvent::class, ['record' => $event->id])
        ->fillForm([
            'title' => 'Forum Ramadan Edit Preview',
            'slug' => 'legacy-event-slug',
            'event_date' => '2026-04-12',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '20:00',
            'end_time' => '22:00',
            'timezone' => 'Asia/Kuala_Lumpur',
            'speakers' => [$speaker->id],
        ])
        ->assertFormSet([
            'slug' => sprintf('forum-ramadan-edit-preview-%s-%s', $speaker->slug, $expectedSuffix),
        ]);
});

it('updates event slugs when a related speaker slug changes', function () {
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $event = createEventForTest([
        'title' => 'Forum Ramadan Pentadbiran',
        'slug' => 'legacy-event-slug',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    OwnerContext::withOwner(null, fn () => app(EventKeyPersonSyncService::class)->sync($event, [$speaker->id]));

    $speaker->update([
        'name' => 'Habib Umar Abdullah',
    ]);

    expect($event->fresh()?->slug)->toBe(sprintf(
        'forum-ramadan-pentadbiran-%s-%s',
        $speaker->fresh()?->slug,
        $expectedSuffix,
    ));
});

it('updates organizer-fallback event slugs when the organizer speaker slug changes', function () {
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $event = createEventForTest([
        'title' => 'Forum Ramadan Fallback Organizer',
        'slug' => sprintf('forum-ramadan-fallback-organizer-%s-%s', $speaker->slug, $expectedSuffix),
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    $event->setPrimaryOrganizer($speaker);

    $speaker->update([
        'name' => 'Habib Umar Abdullah',
    ]);

    expect($event->fresh()?->slug)->toBe(sprintf(
        'forum-ramadan-fallback-organizer-%s-%s',
        $speaker->fresh()?->slug,
        $expectedSuffix,
    ));
});

it('updates event slugs when only the organizer speaker changes', function () {
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $event = createEventForTest([
        'title' => 'Forum Organizer Only Change',
        'slug' => "forum-organizer-only-change-{$expectedSuffix}",
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    $event->setPrimaryOrganizer($institution);

    $event->refresh();
    $event->setPrimaryOrganizer($speaker);
    app(GenerateEventSlugAction::class)->syncEventSlugsForTitle($event->title);

    expect($event->fresh()?->slug)->toBe(sprintf(
        'forum-organizer-only-change-%s-%s',
        $speaker->slug,
        $expectedSuffix,
    ));
});

it('uses the organizer speaker slug when events are created through the raw model path', function () {
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $event = Event::factory()->create([
        'title' => 'Forum Raw Organizer Create',
        'slug' => null,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHours(2),
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'schedule_kind' => 'single',
        'schedule_state' => 'active',
        'status' => 'approved',
        'is_active' => true,
    ]);

    $event->setPrimaryOrganizer($speaker);
    app(GenerateEventSlugAction::class)->syncEventSlugsForTitle($event->title);

    expect($event->fresh()?->slug)->toBe(sprintf(
        'forum-raw-organizer-create-%s-%s',
        $speaker->slug,
        $expectedSuffix,
    ));
});

it('previews the organizer speaker slug when no explicit speakers are selected', function () {
    $administrator = createSlugAdminUser();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Livewire::actingAs($administrator)
        ->test(CreateEvent::class)
        ->fillForm([
            'title' => 'Forum Organizer Preview',
            'slug' => 'temporary-organizer-preview',
            'event_date' => '2026-04-12',
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'timezone' => 'Asia/Kuala_Lumpur',
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'languages' => [101],
            'references' => [],
            'series' => [],
            'speakers' => [],
            'other_key_people' => [],
            'primary_organizer_id' => $speaker->id,
        ])
        ->assertFormSet([
            'slug' => sprintf('forum-organizer-preview-%s-%s', $speaker->slug, $expectedSuffix),
        ]);
});

it('persists the organizer speaker slug when admins create events without explicit speakers', function () {
    $administrator = createSlugAdminUser();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Livewire::actingAs($administrator)
        ->test(CreateEvent::class)
        ->fillForm([
            'title' => 'Forum Organizer Persisted',
            'slug' => 'temporary-organizer-persisted',
            'event_date' => '2026-04-12',
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'timezone' => 'Asia/Kuala_Lumpur',
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'languages' => [101],
            'references' => [],
            'series' => [],
            'speakers' => [],
            'other_key_people' => [],
            'primary_organizer_id' => $speaker->id,
        ])
        ->call('create')
        ->assertHasNoErrors();

    expect(Event::query()->where('title', 'Forum Organizer Persisted')->firstOrFail()->slug)
        ->toBe(sprintf('forum-organizer-persisted-%s-%s', $speaker->slug, $expectedSuffix));
});

it('removes deleted speaker slug segments from related events', function () {
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = OwnerContext::withOwner(null, fn () => Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]));

    $event = createEventForTest([
        'title' => 'Forum Ramadan Pentadbiran',
        'slug' => 'legacy-event-slug',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    OwnerContext::withOwner(null, fn () => app(EventKeyPersonSyncService::class)->sync($event, [$speaker->id]));

    OwnerContext::withOwner(null, fn () => $speaker->delete());

    expect($event->fresh()?->slug)->toBe("forum-ramadan-pentadbiran-{$expectedSuffix}");
});

it('keeps canonical slug sequencing stable when speaker removal makes identities collide', function () {
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = OwnerContext::withOwner(null, fn () => Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]));

    $canonicalEvent = createEventForTest([
        'title' => 'Forum Ramadan Pentadbiran',
        'slug' => 'legacy-event-slug-a',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    $eventWithSpeaker = createEventForTest([
        'title' => 'Forum Ramadan Pentadbiran',
        'slug' => 'legacy-event-slug-b',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    OwnerContext::withOwner(null, fn () => app(EventKeyPersonSyncService::class)->sync($eventWithSpeaker, [$speaker->id]));

    OwnerContext::withOwner(null, fn () => $speaker->delete());

    expect($canonicalEvent->fresh()?->slug)->toBe("forum-ramadan-pentadbiran-{$expectedSuffix}")
        ->and($eventWithSpeaker->fresh()?->slug)->toBe("forum-ramadan-pentadbiran-2-{$expectedSuffix}");
});

it('recomputes event slugs when the event title changes directly', function () {
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $event = createEventForTest([
        'title' => 'Forum Ramadan Pentadbiran',
        'slug' => "forum-ramadan-pentadbiran-{$expectedSuffix}",
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    $event->update([
        'title' => 'Forum Ramadan Dikemas Kini',
    ]);

    expect($event->fresh()?->slug)->toBe("forum-ramadan-dikemas-kini-{$expectedSuffix}");
});

it('renumbers remaining duplicate event slugs when a peer is renamed out of the group', function () {
    $startsAt = Carbon::parse('2026-04-13 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-13', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $first = createEventForTest([
        'title' => 'Kuliah Dhuha Khas',
        'slug' => "kuliah-dhuha-khas-{$expectedSuffix}",
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    $second = createEventForTest([
        'title' => 'Kuliah Dhuha Khas',
        'slug' => "kuliah-dhuha-khas-2-{$expectedSuffix}",
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    $first->update([
        'title' => 'Kuliah Dhuha Khas Perdana',
    ]);

    expect($first->fresh()?->slug)->toBe("kuliah-dhuha-khas-perdana-{$expectedSuffix}")
        ->and($second->fresh()?->slug)->toBe("kuliah-dhuha-khas-{$expectedSuffix}");
});

it('uses the generated dated slug for advanced parent program creation', function () {
    $user = User::factory()->create(['name' => 'Parent Program Member']);
    $institution = Institution::factory()->create(['name' => 'Masjid Pusat']);
    $institution->members()->syncWithoutDetaching([$user->id]);

    $startsAt = now()->addDays(8)->setTime(20, 0);
    $expectedSuffix = $startsAt->copy()->format('j-n-y');

    Livewire::actingAs($user)
        ->test(CreateAdvanced::class)
        ->set('form.title', 'Ramadan Knowledge Series')
        ->set('form.description', 'A month-long umbrella program.')
        ->set('form.program_starts_at', $startsAt->format('Y-m-d\TH:i'))
        ->set('form.program_ends_at', $startsAt->copy()->addDays(20)->setTime(22, 0)->format('Y-m-d\TH:i'))
        ->set('form.primary_organizer_id', $institution->id)
        ->set('form.default_event_type', 'kuliah_ceramah')
        ->set('form.default_event_format', 'physical')
        ->call('submit')
        ->assertHasNoErrors();

    $event = Event::query()->where('title', 'Ramadan Knowledge Series')->firstOrFail();

    expect($event->slug)->toBe("ramadan-knowledge-series-{$expectedSuffix}");
});

it('backfills existing event slugs through the queued job logic', function () {
    $eventDate = now('Asia/Kuala_Lumpur')->addDays(9)->setTime(20, 0);
    $expectedSuffix = $eventDate->copy()->format('j-n-y');

    $first = createEventForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000041',
        title: 'Kuliah Dhuha Khas',
        slug: 'legacy-event-1',
        startsAt: $eventDate,
    );

    $second = createEventForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000042',
        title: 'Kuliah Dhuha Khas',
        slug: 'legacy-event-2',
        startsAt: $eventDate,
    );

    app(BackfillEventSlugs::class)->handle(
        app(GenerateEventSlugAction::class),
        app(PublicListingsCache::class),
    );

    expect($first->fresh()?->slug)->toBe("kuliah-dhuha-khas-{$expectedSuffix}")
        ->and($second->fresh()?->slug)->toBe("kuliah-dhuha-khas-2-{$expectedSuffix}");
});

it('queues the event slug backfill command', function () {
    Queue::fake();

    $this->artisan('events:queue-slug-backfill')
        ->expectsOutput('Queued event slug backfill job.')
        ->assertSuccessful();

    Queue::assertPushed(BackfillEventSlugs::class);
});

function createSlugAdminUser(): User
{
    test()->seed(PermissionSeeder::class);
    test()->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    return $administrator;
}

function createVenueSlugGeography(
    string $countryName = 'Malaysia',
    string $countryIso2 = 'MY',
    string $countryIso3 = 'MYS',
    string $stateName = 'Selangor',
    string $districtName = 'Petaling',
    string $subdistrictName = 'Shah Alam',
): array {
    $country = AddressCountry::query()->where('iso2', $countryIso2)->first();

    if (! $country instanceof AddressCountry) {
        $country = AddressCountry::query()->create([
            'name' => $countryName,
            'iso2' => $countryIso2,
            'iso3' => $countryIso3,
            'phone_code' => '60',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);
    }

    $state = AddressArea::query()->create([
        'country_id' => $country->getKey(),
        'country_code' => $countryIso2,
        'name' => $stateName,
        'type' => 'state',
        'level' => 1,
        'slug' => Str::slug($stateName),
        'source' => 'manual',
        'source_id' => (string) Str::ulid(),
    ]);

    $district = AddressArea::query()->create([
        'country_id' => $country->getKey(),
        'parent_id' => $state->getKey(),
        'country_code' => $countryIso2,
        'name' => $districtName,
        'type' => 'district',
        'level' => 2,
        'slug' => Str::slug($districtName),
        'source' => 'manual',
        'source_id' => (string) Str::ulid(),
    ]);

    $subdistrict = AddressArea::query()->create([
        'country_id' => $country->getKey(),
        'parent_id' => $district->getKey(),
        'country_code' => $countryIso2,
        'name' => $subdistrictName,
        'type' => 'subdistrict',
        'level' => 3,
        'slug' => Str::slug($subdistrictName),
        'source' => 'manual',
        'source_id' => (string) Str::ulid(),
    ]);

    return [
        'country' => $country,
        'state' => $state,
        'district' => $district,
        'subdistrict' => $subdistrict,
    ];
}

/**
 * @param  array{
 *     country: AddressCountry,
 *     state: AddressArea,
 *     district: AddressArea,
 *     subdistrict: AddressArea
 * }  $geography
 * @return array<string, string>
 */
function venueGeographyAddressPayload(array $geography): array
{
    return [
        'country_id' => (string) $geography['country']->getKey(),
        'admin_area_1_id' => (string) $geography['state']->getKey(),
        'admin_area_2_id' => (string) $geography['district']->getKey(),
        'admin_area_3_id' => (string) $geography['subdistrict']->getKey(),
    ];
}

/**
 * @param  array{
 *     country: AddressCountry,
 *     state: AddressArea,
 *     district: AddressArea,
 *     subdistrict: AddressArea
 * }  $geography
 */
function createVenueForSlugBackfill(string $id, string $name, string $slug, array $geography): Venue
{
    $venue = Venue::withoutEvents(fn () => Venue::unguarded(fn () => Venue::query()->create([
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'type' => 'dewan',
        'status' => 'verified',
        'is_active' => true,
        'visibility' => 'public',
    ])));

    Venue::withoutEvents(function () use ($venue, $geography): void {
        $address = Address::withoutEvents(fn () => Address::query()->create([
            'country_id' => $geography['country']->getKey(),
            'admin_area_1_id' => $geography['state']->getKey(),
            'admin_area_2_id' => $geography['district']->getKey(),
            'admin_area_3_id' => $geography['subdistrict']->getKey(),
        ]));

        $venue->attachAddress($address, type: 'main', isPrimary: true);
    });

    return $venue->fresh(['address']) ?? $venue;
}

function createDefaultCountry(): AddressCountry
{
    $country = AddressCountry::query()->where('iso2', 'MY')->first();

    if (! $country instanceof AddressCountry) {
        $country = AddressCountry::query()->create([
            'name' => 'Malaysia',
            'iso2' => 'MY',
            'iso3' => 'MYS',
            'phone_code' => '60',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);
    }

    return $country;
}

function createEventForTest(array $attributes = []): Event
{
    return OwnerContext::withOwner(null, fn () => Event::factory()->create($attributes));
}

function createEventForSlugBackfill(string $id, string $title, string $slug, CarbonInterface $startsAt): Event
{
    return OwnerContext::withOwner(null, fn () => Event::unguarded(fn () => Event::query()->create([
        'id' => $id,
        'title' => $title,
        'slug' => $slug,
        'event_structure' => 'standalone',
        'starts_at' => Carbon::instance($startsAt)->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ])));
}
