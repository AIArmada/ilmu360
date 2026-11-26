<?php

declare(strict_types=1);

use AIArmada\Addressing\Features\AddressLifecycle\Models\Address;
use AIArmada\CommerceSupport\Persistence\VersionedCache;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\Pages\CreateDonationChannel;
use App\Features\Events\Models\Event;
use App\Features\Events\Persistence\EventHomepageStatsCacheStore;
use App\Features\Inspirations\Api\InspirationReader;
use App\Features\Inspirations\Models\Inspiration;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Api\InstitutionCards;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Persistence\InstitutionHomepageStatsCacheStore;
use App\Features\Persons\Api\SpeakerStatus;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Persistence\PersonHomepageStatsCacheStore;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Features\References\Persistence\ReferenceHomepageStatsCacheStore;
use App\Features\Registration\Api\AdmissionRefunds;
use App\Features\Registration\EntryPoints\Livewire\Pages\Refund;
use App\Features\Registration\Models\Registration;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

it('restricts bulk policy actions on venues and spaces to super admins', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    foreach ([Venue::class, Space::class] as $modelClass) {
        foreach (['deleteAny', 'restoreAny', 'forceDeleteAny'] as $ability) {
            expect($moderator->can($ability, $modelClass))->toBeFalse()
                ->and($superAdmin->can($ability, $modelClass))->toBeTrue();
        }
    }
});

it('escapes html and json-ld breakouts in review preview and structured data views', function (): void {
    $jsonLdOutput = view('components.event-json-ld', [
        'jsonLd' => static fn (): array => [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => '</script><script>alert(1)</script>',
        ],
    ])->render();

    expect($jsonLdOutput)
        ->not->toContain('</script><script>')
        ->toContain('\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E');

    $reviewHtml = view('components.pages.submit-event.partials.review-preview', [
        'get' => static fn (string $key): mixed => $key === 'description' ? '<p>Nota</p><script>alert(1)</script>' : null,
        'showAdvanced' => false,
        'institutionOptions' => [],
        'personOptions' => [],
        'venueOptions' => [],
        'languageOptions' => [],
        'eventCategoryOptions' => [],
        'domainTagOptions' => [],
        'disciplineTagOptions' => [],
        'sourceTagOptions' => [],
        'issueTagOptions' => [],
        'referenceOptions' => [],
        'seriesOptions' => [],
        'selectedEventDate' => null,
    ])->render();

    expect($reviewHtml)
        ->toContain('<p>Nota</p>')
        ->not->toContain('<script>alert(1)</script>');
});

it('excludes non-public institutions and non-speaker or draft persons from public sitemaps', function (): void {
    $publicInstitution = Institution::factory()->create([
        'status' => InstitutionStatus::Verified,
        'slug' => 'masjid-public-sitemap',
    ]);
    $draftInstitution = Institution::factory()->create([
        'status' => InstitutionStatus::Rejected,
        'slug' => 'masjid-rejected-sitemap',
    ]);

    $publicSpeaker = Person::factory()->create([
        'status' => 'verified',
        'speaker_status' => SpeakerStatus::Active,
        'slug' => 'ustaz-public-sitemap',
    ]);
    $nonSpeakerPerson = Person::factory()->create([
        'status' => 'verified',
        'speaker_status' => SpeakerStatus::Inactive,
        'slug' => 'non-speaker-sitemap',
    ]);

    $this->get(route('sitemap.institutions'))
        ->assertOk()
        ->assertSee(route('institutions.show', $publicInstitution->slug), false)
        ->assertDontSee(route('institutions.show', $draftInstitution->slug), false);

    $this->get(route('sitemap.persons'))
        ->assertOk()
        ->assertSee(route('persons.show', $publicSpeaker->slug), false)
        ->assertDontSee(route('persons.show', $nonSpeakerPerson->slug), false);
});

it('attaches throttle middleware to diagnostics, sitemaps, and public api discovery endpoints', function (): void {
    expect(Route::getRoutes()->getByName('network-diagnostics')?->gatherMiddleware())->toContain('throttle:10,1')
        ->and(Route::getRoutes()->getByName('sitemap.index')?->gatherMiddleware())->toContain('throttle:search')
        ->and(Route::getRoutes()->getByName('sitemap.events')?->gatherMiddleware())->toContain('throttle:search')
        ->and(Route::getRoutes()->getByName('api.client.search.index')?->gatherMiddleware())->toContain('throttle:search')
        ->and(Route::getRoutes()->getByName('api.events.index')?->gatherMiddleware())->toContain('throttle:search');
});

it('re-authorizes refund requests during livewire submit instead of trusting mounted state', function (): void {
    $owner = User::factory()->create();
    $event = Event::factory()->create(['status' => 'approved']);
    $registration = Registration::factory()->create([
        'event_id' => $event->getKey(),
        'registrant_type' => $owner->getMorphClass(),
        'registrant_id' => $owner->getKey(),
        'status' => 'confirmed',
        'total_amount' => 5000,
    ]);

    $this->mock(AdmissionRefunds::class, function ($mock): void {
        $mock->shouldNotReceive('process');
    });

    $component = Livewire::actingAs($owner)
        ->test(Refund::class, ['event' => $event, 'registration' => $registration]);

    // Revoke ownership after mount before submit is called.
    $otherUser = User::factory()->create();
    $registration->update(['registrant_id' => $otherUser->getKey()]);

    $component
        ->set('confirmation', true)
        ->call('submit')
        ->assertNotFound();
});

it('searches events by title without sql column errors in donation channel form', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $event = Event::factory()->create([
        'title' => 'Kuliah Kitab Turath Perdana',
        'status' => 'approved',
    ]);

    Livewire::actingAs($admin)
        ->test(CreateDonationChannel::class)
        ->fillForm(['donatable_type' => Event::class])
        ->assertFormFieldExists('donatable_id', fn (Select $field): bool => array_key_exists(
            (string) $event->getKey(),
            $field->getSearchResults('Turath'),
        ));
});

it('caches active inspiration ids with versioned invalidation on save and delete', function (): void {
    $first = Inspiration::factory()->create([
        'locale' => 'ms',
        'title' => 'Inspirasi Pertama',
        'status' => 'active',
    ]);

    $reader = app(InspirationReader::class);
    $initialCard = $reader->random('ms');
    expect($initialCard?->id)->toBe((string) $first->getKey());

    $first->delete();
    $second = Inspiration::factory()->create([
        'locale' => 'ms',
        'title' => 'Inspirasi Kedua',
        'status' => 'active',
    ]);

    $updatedCard = $reader->random('ms');
    expect($updatedCard?->id)->toBe((string) $second->getKey());
});

it('filters nearby institutions accurately using GeoDistance bounding box and haversine helpers', function (): void {
    $klNear = Institution::factory()->create([
        'status' => InstitutionStatus::Verified,
        'name' => 'Masjid Jamek KL',
    ]);
    $nearAddress = Address::factory()->create(['latitude' => 3.1490, 'longitude' => 101.6960]);
    $klNear->attachAddress($nearAddress, type: 'main', isPrimary: true);

    $jbFar = Institution::factory()->create([
        'status' => InstitutionStatus::Verified,
        'name' => 'Masjid Sultan Abu Bakar',
    ]);
    $farAddress = Address::factory()->create(['latitude' => 1.4556, 'longitude' => 103.7510]);
    $jbFar->attachAddress($farAddress, type: 'main', isPrimary: true);

    $cards = app(InstitutionCards::class)->forSearch('Masjid', lat: 3.1478, lng: 101.6953, radiusKm: 10, limit: 10);

    expect($cards['total'])->toBe(1)
        ->and($cards['items'])->toHaveCount(1)
        ->and($cards['items'][0]['id'])->toBe((string) $klNear->getKey());
});

it('coerces stringified numeric cache values into strict integers in homepage stats and versioned cache stores', function (): void {
    $statsVersion = app(App\Shared\Cache\DiscoveryCache::class)
        ->version((string) config('discovery.cache.versions.homepage_stats'));

    Cache::put("home:stats:events:active:{$statsVersion}", '42', 3600);
    Cache::put("home:stats:persons:active:{$statsVersion}", '17', 3600);
    Cache::put("home:stats:institutions:active:{$statsVersion}", '9', 3600);
    Cache::put("home:stats:references:active:{$statsVersion}", '5', 3600);

    Cache::forever('test.int.version', 'v-test');
    Cache::put('test.int.key:v-test', '88', 60);

    expect(app(EventHomepageStatsCacheStore::class)->activeEventCount())->toBe(42)
        ->and(app(PersonHomepageStatsCacheStore::class)->activePersonCount())->toBe(17)
        ->and(app(InstitutionHomepageStatsCacheStore::class)->activeInstitutionCount())->toBe(9)
        ->and(app(ReferenceHomepageStatsCacheStore::class)->rootReferenceCount())->toBe(5)
        ->and(app(VersionedCache::class)->rememberInt('test.int.version', 'test.int.key', 60, static fn (): int => 0))->toBe(88);
});
