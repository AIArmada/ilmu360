<?php

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Models\SignalEvent;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Filament\Resources\SlugRedirects\Pages\CreateSlugRedirect;
use App\Filament\Resources\SlugRedirects\Pages\EditSlugRedirect;
use App\Filament\Resources\SlugRedirects\Pages\ListSlugRedirects;
use App\Forms\VenueFormSchema;
use App\Models\Event;
use App\Models\Person;
use App\Models\Reference;
use App\Models\SlugRedirect;
use App\Models\User;
use App\Models\Venue;
use App\Services\ContributionEntityMutationService;
use App\Services\EventKeyPersonSyncService;
use App\Services\Signals\SignalsTracker;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('registers public slug route binders during app boot', function () {
    foreach (['event', 'institution', 'person', 'venue', 'reference'] as $parameter) {
        expect(app('router')->getBindingCallback($parameter))
            ->not->toBeNull();
    }
});

it('creates an institution slug redirect only after the old public path has been visited', function () {
    $proposer = User::factory()->create();
    $geography = createSlugRedirectGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Lama',
        'type' => 'masjid',
        'address' => slugRedirectAddressPayload($geography),
    ], $proposer);

    $oldPath = route('institutions.show', $institution, false);
    $oldSlug = $institution->slug;
    recordVisitedPath($oldPath);

    $institution->update([
        'name' => 'Masjid Baru',
    ]);

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($redirect->source_slug)->toBe($oldSlug)
        ->and($redirect->source_path)->toBe($oldPath)
        ->and($redirect->destination_slug)->toBe($institution->fresh()->slug)
        ->and($redirect->destination_path)->toBe(route('institutions.show', $institution->fresh(), false));
});

it('creates a person slug redirect when a visited slug changes', function () {
    $proposer = User::factory()->create();
    $country = createSlugRedirectCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Lama',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    $oldPath = route('persons.show', $person, false);
    $oldSlug = $person->slug;
    recordVisitedPath($oldPath);

    $person->update([
        'name' => 'Ustaz Baru',
    ]);

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($redirect->source_slug)->toBe($oldSlug)
        ->and($redirect->source_path)->toBe($oldPath)
        ->and($redirect->destination_slug)->toBe($person->fresh()->slug)
        ->and($redirect->destination_path)->toBe(route('persons.show', $person->fresh(), false));
});

it('creates a reference slug redirect when a visited title slug changes', function () {
    $reference = Reference::query()->create([
        'title' => 'Kitab Lama',
        'type' => 'book',
        'status' => 'verified',
    ]);

    $oldPath = route('references.show', $reference, false);
    $oldSlug = $reference->slug;
    recordVisitedPath($oldPath);

    $reference->update([
        'title' => 'Kitab Baru',
    ]);

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($redirect->source_slug)->toBe($oldSlug)
        ->and($redirect->source_path)->toBe($oldPath)
        ->and($redirect->destination_slug)->toBe($reference->fresh()->slug)
        ->and($redirect->destination_path)->toBe(route('references.show', $reference->fresh(), false));
});

it('persists canonical slug changes through the shared slug synchronizer', function () {
    $reference = Reference::query()->create([
        'title' => 'Kitab Sinkron Lama',
        'type' => 'book',
        'status' => 'verified',
    ]);

    $oldPath = route('references.show', $reference, false);
    recordVisitedPath($oldPath);

    $didChange = app(SyncCanonicalSlugAction::class)->persist($reference, 'kitab-sinkron-baru');

    $reference->refresh();
    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($didChange)->toBeTrue()
        ->and($reference->slug)->toBe('kitab-sinkron-baru')
        ->and($redirect->source_slug)->toBe('kitab-sinkron-lama')
        ->and($redirect->destination_slug)->toBe('kitab-sinkron-baru')
        ->and($redirect->destination_path)->toBe(route('references.show', $reference, false));
});

it('creates an event slug redirect when a visited dated slug changes', function () {
    $event = createSlugRedirectEvent(
        id: '00000000-0000-0000-0000-000000000071',
        title: 'Majlis Lama',
        slug: 'majlis-lama-12-4-26',
        startsAt: Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
    );

    $oldPath = route('events.show', $event, false);
    $oldSlug = $event->slug;
    recordVisitedPath($oldPath);

    $event->update([
        'title' => 'Majlis Baru',
    ]);

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($redirect->source_slug)->toBe($oldSlug)
        ->and($redirect->source_path)->toBe($oldPath)
        ->and($redirect->destination_slug)->toBe($event->fresh()->slug)
        ->and($redirect->destination_path)->toBe(route('events.show', $event->fresh(), false));
});

it('creates an event slug redirect even when the old slug was never visited', function () {
    $event = createSlugRedirectEvent(
        id: '00000000-0000-0000-0000-000000000074',
        title: 'Majlis Tanpa Lawatan',
        slug: 'majlis-tanpa-lawatan-12-4-26',
        startsAt: Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
    );

    $oldPath = route('events.show', $event, false);
    $oldSlug = $event->slug;

    $event->update([
        'title' => 'Majlis Tanpa Lawatan Baru',
    ]);

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($oldSlug)->not->toBe($event->fresh()->slug)
        ->and($redirect->source_slug)->toBe($oldSlug)
        ->and($redirect->destination_slug)->toBe($event->fresh()->slug)
        ->and($redirect->destination_path)->toBe(route('events.show', $event->fresh(), false));

    $this->get($oldPath)
        ->assertRedirect(route('events.show', $event->fresh()));
});

it('redirects old event slugs when a related person slug changes', function () {
    $person = Person::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
    ]);

    $event = createSlugRedirectEvent(
        id: '00000000-0000-0000-0000-000000000072',
        title: 'Majlis Penceramah Lama',
        slug: 'majlis-penceramah-lama-habib-umar-12-4-26',
        startsAt: Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
    );

    app(EventKeyPersonSyncService::class)->sync($event, [$person->id]);

    $oldPath = route('events.show', $event->fresh(), false);
    recordVisitedPath($oldPath);

    $person->update([
        'name' => 'Habib Umar Abdullah',
    ]);

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($redirect->source_path)->toBe($oldPath)
        ->and($redirect->destination_path)->toBe(route('events.show', $event->fresh(), false));

    $this->get($oldPath)
        ->assertRedirect(route('events.show', $event->fresh()));
});

it('redirects old event slugs when only the organizer person changes', function () {
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $person = Person::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
    ]);

    $event = createSlugRedirectEvent(
        id: '00000000-0000-0000-0000-000000000073',
        title: 'Majlis Organizer Tukar',
        slug: 'majlis-organizer-tukar-12-4-26',
        startsAt: Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
    );

    $oldPath = route('events.show', $event, false);
    recordVisitedPath($oldPath);

    $event->refresh();
    OwnerContext::withOwner(null, function () use ($event, $person): void {
        $event->setPrimaryOrganizer($person);
        app(GenerateEventSlugAction::class)->syncEventSlugsForTitle($event->title);
    });

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($event->fresh()?->slug)->toBe(sprintf(
        'majlis-organizer-tukar-%s-%s',
        $person->fresh()?->slug,
        $expectedSuffix,
    ))
        ->and($redirect->source_path)->toBe($oldPath)
        ->and($redirect->destination_path)->toBe(route('events.show', $event->fresh(), false));

    $this->get($oldPath)
        ->assertRedirect(route('events.show', $event->fresh()));
});

it('does not create redirect rows for unvisited slug changes', function () {
    $proposer = User::factory()->create();
    $geography = createSlugRedirectGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Tidak Dilawat',
        'type' => 'masjid',
        'address' => slugRedirectAddressPayload($geography),
    ], $proposer);

    $oldPath = route('institutions.show', $institution, false);
    $oldSlug = $institution->slug;

    $institution->update([
        'name' => 'Masjid Tidak Dilawat Baru',
    ]);

    expect($oldSlug)->not->toBe($institution->fresh()->slug)
        ->and(SlugRedirect::query()->where('source_path', $oldPath)->exists())->toBeFalse();

    $this->get($oldPath)
        ->assertNotFound();
});

it('creates a venue slug redirect when a visited geographic slug changes', function () {
    $geography = createSlugRedirectGeography();

    $venueId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Lama',
        'type' => 'dewan',
        'address' => slugRedirectAddressPayload($geography),
    ]);

    $venue = Venue::query()->findOrFail($venueId);

    $oldPath = route('venues.show', $venue, false);
    $oldSlug = $venue->slug;
    recordVisitedPath($oldPath);

    $venue->update([
        'name' => 'Dewan Baru',
    ]);

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    expect($redirect->source_slug)->toBe($oldSlug)
        ->and($redirect->source_path)->toBe($oldPath)
        ->and($redirect->destination_slug)->toBe($venue->fresh()->slug)
        ->and($redirect->destination_path)->toBe(route('venues.show', $venue->fresh(), false));
});

it('redirects old venue slugs to the current canonical public url', function () {
    $geography = createSlugRedirectGeography();

    $venueId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Laluan Lama',
        'type' => 'dewan',
        'address' => slugRedirectAddressPayload($geography),
    ]);

    $venue = Venue::query()->findOrFail($venueId);

    $oldPath = route('venues.show', $venue, false);
    recordVisitedPath($oldPath);

    $venue->update([
        'name' => 'Dewan Laluan Baru',
    ]);

    $this->get($oldPath)
        ->assertRedirect(route('venues.show', $venue->fresh()));
});

it('redirects old institution slugs to the current canonical public url', function () {
    $proposer = User::factory()->create();
    $geography = createSlugRedirectGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Laluan Lama',
        'type' => 'masjid',
        'address' => slugRedirectAddressPayload($geography),
    ], $proposer);

    $oldPath = route('institutions.show', $institution, false);
    recordVisitedPath($oldPath);

    $institution->update([
        'name' => 'Masjid Laluan Baru',
    ]);

    $this->get($oldPath)
        ->assertRedirect(route('institutions.show', $institution->fresh()));
});

it('shows slug redirects in the admin resource table', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $proposer = User::factory()->create();
    $geography = createSlugRedirectGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Admin Lama',
        'type' => 'masjid',
        'address' => slugRedirectAddressPayload($geography),
    ], $proposer);

    $oldPath = route('institutions.show', $institution, false);
    recordVisitedPath($oldPath);

    $institution->update([
        'name' => 'Masjid Admin Baru',
    ]);

    $redirect = SlugRedirect::query()->where('source_path', $oldPath)->firstOrFail();

    Livewire::actingAs($administrator)
        ->test(ListSlugRedirects::class)
        ->assertCanSeeTableRecords([$redirect])
        ->assertSee($redirect->source_path)
        ->assertSee($redirect->destination_path);
});

it('allows administrators to create slug redirects in the admin resource', function () {
    $administrator = slugRedirectAdministrator();
    $proposer = User::factory()->create();
    $geography = createSlugRedirectGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid CRUD Baru',
        'type' => 'masjid',
        'address' => slugRedirectAddressPayload($geography),
    ], $proposer);

    Livewire::actingAs($administrator)
        ->test(CreateSlugRedirect::class)
        ->fillForm([
            'redirectable_type' => 'institution',
            'redirectable_id' => (string) $institution->getKey(),
            'source_slug' => 'masjid-crud-lama-shah-alam-petaling-selangor-my',
            'redirect_count' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $redirect = SlugRedirect::query()
        ->where('source_path', '/institusi/masjid-crud-lama-shah-alam-petaling-selangor-my')
        ->firstOrFail();

    expect($redirect->redirectable_type)->toBe('institution')
        ->and($redirect->redirectable_id)->toBe((string) $institution->getKey())
        ->and($redirect->source_slug)->toBe('masjid-crud-lama-shah-alam-petaling-selangor-my')
        ->and($redirect->source_path)->toBe('/institusi/masjid-crud-lama-shah-alam-petaling-selangor-my')
        ->and($redirect->destination_slug)->toBe($institution->slug)
        ->and($redirect->destination_path)->toBe(route('institutions.show', $institution, false));
});

it('allows administrators to edit slug redirects in the admin resource', function () {
    $administrator = slugRedirectAdministrator();
    $proposer = User::factory()->create();
    $geography = createSlugRedirectGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid CRUD Edit',
        'type' => 'masjid',
        'address' => slugRedirectAddressPayload($geography),
    ], $proposer);

    $redirect = SlugRedirect::query()->create([
        'redirectable_type' => $institution->getMorphClass(),
        'redirectable_id' => (string) $institution->getKey(),
        'source_slug' => 'masjid-crud-asal-shah-alam-petaling-selangor-my',
        'source_path' => '/institusi/masjid-crud-asal-shah-alam-petaling-selangor-my',
        'destination_slug' => $institution->slug,
        'destination_path' => route('institutions.show', $institution, false),
        'redirect_count' => 1,
    ]);

    Livewire::actingAs($administrator)
        ->test(EditSlugRedirect::class, ['record' => $redirect->getKey()])
        ->fillForm([
            'redirectable_type' => 'institution',
            'redirectable_id' => (string) $institution->getKey(),
            'source_slug' => 'masjid-crud-kemaskini-shah-alam-petaling-selangor-my',
            'redirect_count' => 7,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $redirect->refresh();

    expect($redirect->source_slug)->toBe('masjid-crud-kemaskini-shah-alam-petaling-selangor-my')
        ->and($redirect->source_path)->toBe('/institusi/masjid-crud-kemaskini-shah-alam-petaling-selangor-my')
        ->and($redirect->redirect_count)->toBe(7)
        ->and($redirect->destination_slug)->toBe($institution->slug)
        ->and($redirect->destination_path)->toBe(route('institutions.show', $institution, false));
});

it('allows administrators to delete slug redirects in the admin resource', function () {
    $administrator = slugRedirectAdministrator();
    $proposer = User::factory()->create();
    $geography = createSlugRedirectGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid CRUD Padam',
        'type' => 'masjid',
        'address' => slugRedirectAddressPayload($geography),
    ], $proposer);

    $redirect = SlugRedirect::query()->create([
        'redirectable_type' => $institution->getMorphClass(),
        'redirectable_id' => (string) $institution->getKey(),
        'source_slug' => 'masjid-crud-padam-shah-alam-petaling-selangor-my',
        'source_path' => '/institusi/masjid-crud-padam-shah-alam-petaling-selangor-my',
        'destination_slug' => $institution->slug,
        'destination_path' => route('institutions.show', $institution, false),
        'redirect_count' => 0,
    ]);

    Livewire::actingAs($administrator)
        ->test(ListSlugRedirects::class)
        ->callTableAction('delete', $redirect->getKey())
        ->assertHasNoTableActionErrors();

    expect(SlugRedirect::query()->find($redirect->getKey()))->toBeNull();
});

function createSlugRedirectCountry(
    string $countryName = 'Malaysia',
    string $countryIso2 = 'MY',
    string $countryIso3 = 'MYS',
    int|string|null $countryId = null,
    string $phoneCode = '60',
): AddressCountry {
    $country = $countryId !== null
        ? AddressCountry::query()->find((string) $countryId)
        : null;

    if ($country instanceof AddressCountry) {
        return $country;
    }

    $country = AddressCountry::query()->where('iso2', strtoupper($countryIso2))->first();

    if ($country instanceof AddressCountry) {
        return $country;
    }

    $country = new AddressCountry;
    $country->forceFill([
        'name' => $countryName,
        'entity_type' => 'country',
        'iso2' => $countryIso2,
        'iso3' => $countryIso3,
        'phone_code' => $phoneCode,
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    if ($countryId !== null) {
        $country->forceFill([
            'id' => (string) $countryId,
        ]);
    }

    $country->save();

    return $country;
}

function slugRedirectAdministrator(): User
{
    test()->seed(RoleSeeder::class);
    test()->seed(PermissionSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    return $administrator;
}

/**
 * @return array{country: AddressCountry, state: AddressArea, district: AddressArea, subdistrict: AddressArea}
 */
function createSlugRedirectGeography(): array
{
    $country = createSlugRedirectCountry();
    $state = createTestAddressArea('Selangor', 1, country: $country);
    $district = createTestAddressArea('Petaling', 2, parent: $state, country: $country);
    $subdistrict = createTestAddressArea('Shah Alam', 3, parent: $district, country: $country);

    return [
        'country' => $country,
        'state' => $state,
        'district' => $district,
        'subdistrict' => $subdistrict,
    ];
}

/**
 * @param  array{country: AddressCountry, state: AddressArea, district: AddressArea, subdistrict: AddressArea}  $geography
 * @return array<string, string>
 */
function slugRedirectAddressPayload(array $geography): array
{
    return [
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'admin_area_1_id' => (string) $geography['district']->getKey(),
        'admin_area_2_id' => (string) $geography['subdistrict']->getKey(),
    ];
}

function recordVisitedPath(string $path): void
{
    $trackedProperty = app(SignalsTracker::class)->defaultTrackedProperty();

    expect($trackedProperty)->not->toBeNull();

    SignalEvent::query()
        ->withoutOwnerScope()
        ->create([
            'tracked_property_id' => (string) $trackedProperty?->getKey(),
            'occurred_at' => now(),
            'event_name' => (string) config('signals.defaults.page_view_event_name', 'page_view'),
            'event_category' => 'page_view',
            'path' => $path,
            'url' => url($path),
            'currency' => 'MYR',
            'revenue_minor' => 0,
        ]);
}

function createSlugRedirectEvent(string $id, string $title, string $slug, Carbon $startsAt): Event
{
    return Event::factory()->create([
        'id' => $id,
        'title' => $title,
        'slug' => $slug,
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_category_ids' => [eventCategoryId('other')],
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'children_allowed' => true,
        'delivery_mode' => 'physical',
        'visibility' => 'public',
        'status' => 'approved',
    ]);
}
