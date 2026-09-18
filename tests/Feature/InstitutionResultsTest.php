<?php

use App\Enums\InstitutionNameType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Support\Search\InstitutionSearchService;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('allows authenticated users to follow and unfollow an institution from the directory card', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Directory Follow Institution',
        'status' => 'verified',
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.institutions.results')
        ->assertSee('<article', false)
        ->assertSee('data-follow-icon="institution"', false)
        ->assertSee('data-follow-state="not-following"', false)
        ->assertSee('aria-label="Ikuti"', false)
        ->assertSee('wire:click.stop.prevent="toggleFollow(\''.$institution->id.'\')"', false)
        ->call('toggleFollow', (string) $institution->id)
        ->assertSet('followingInstitutionIds', [(string) $institution->id])
        ->assertSee('data-follow-state="following"', false)
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('fill="currentColor"', false);

    expect($user->isFollowing($institution))->toBeTrue();

    $component
        ->call('toggleFollow', (string) $institution->id)
        ->assertSet('followingInstitutionIds', [])
        ->assertSee('data-follow-state="not-following"', false)
        ->assertSee('aria-pressed="false"', false);

    expect($user->isFollowing($institution))->toBeFalse();
});

it('hydrates an existing institution follow in the directory card', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Already Followed Institution',
        'status' => 'verified',
    ]);

    $user->follow($institution);

    Livewire::actingAs($user)
        ->test('pages.institutions.results')
        ->assertSet('followingInstitutionIds', [(string) $institution->id])
        ->assertSee('data-follow-state="following"', false)
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('fill="currentColor"', false);
});

it('redirects guests to login when trying to follow from an institution directory card', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    Livewire::test('pages.institutions.results')
        ->call('toggleFollow', (string) $institution->id)
        ->assertRedirect(route('login', ['redirect' => route('institutions.index', absolute: false)]));
});

it('uses a stable random institution order instead of alphabetical sorting', function () {
    $sessionSeed = 'institution-index-test-seed';
    session([Institution::PUBLIC_DIRECTORY_SESSION_KEY => $sessionSeed]);

    $firstAlphabetical = Institution::factory()->create([
        'name' => 'Adam Institusi Rawak',
        'status' => 'verified',
    ]);

    $secondAlphabetical = Institution::factory()->create([
        'name' => 'Zaid Institusi Rawak',
        'status' => 'verified',
    ]);

    $component = Livewire::test('pages.institutions.results');

    $orderedIds = collect($component->instance()->institutions->items())
        ->pluck('id')
        ->all();

    $expectedOrder = Institution::query()
        ->whereIn('institutions.id', [$firstAlphabetical->id, $secondAlphabetical->id])
        ->publicDirectoryOrder()
        ->pluck('institutions.id')
        ->map(static fn (mixed $id): string => (string) $id)
        ->all();

    expect(array_values(array_intersect($orderedIds, [$firstAlphabetical->id, $secondAlphabetical->id])))
        ->toBe($expectedOrder);
});

it('syncs institution results when filters update', function () {
    Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Pusat Pengajian An-Nur',
        'status' => 'verified',
    ]);

    Livewire::test('pages.institutions.results')
        ->call('syncFilters', ['search' => 'Hidayh'])
        ->assertSee('Masjid Al Hidayah')
        ->assertDontSee('Pusat Pengajian An-Nur');
});

it('refreshes cached institution search results after institution updates', function () {
    $searchService = app(InstitutionSearchService::class);
    $institution = Institution::factory()
        ->create([
            'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
            'status' => 'verified',
        ]);

    $institution->names()->create([
        'name_type' => InstitutionNameType::Nickname,
        'full_name' => 'Masjid Biru',
        'language_code' => 'ms',
        'is_primary' => true,
    ]);

    expect($searchService->publicSearchIds('biru'))
        ->toContain((string) $institution->id);

    $institution->names()->updateOrCreate(
        ['name_type' => InstitutionNameType::Nickname],
        ['full_name' => 'Masjid Hijau', 'language_code' => 'ms', 'is_primary' => true]
    );
    $institution->touch();

    expect($searchService->publicSearchIds('biru'))
        ->not->toContain((string) $institution->id)
        ->and($searchService->publicSearchIds('hijau'))
        ->toContain((string) $institution->id);

    $updatedSearchResults = Livewire::test('pages.institutions.results')
        ->call('syncFilters', ['search' => 'hijau'])
        ->instance()
        ->institutions;

    expect(collect($updatedSearchResults->items())->pluck('id')->all())
        ->toContain((string) $institution->id);
});

it('shows the nearest upcoming public majlis on institution cards', function () {
    $institution = Institution::factory()->create([
        'name' => 'Institusi Majlis Terdekat',
        'slug' => 'institusi-majlis-terdekat',
        'status' => 'verified',
    ]);

    Event::factory()->for($institution)->create([
        'title' => 'Majlis Institusi Lebih Lewat',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(7),
        'published_at' => now(),
    ]);

    $nearestEvent = Event::factory()->for($institution)->create([
        'title' => 'Majlis Institusi Terdekat',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
        'published_at' => now(),
    ]);

    $component = Livewire::test('pages.institutions.results', [
        'filters' => ['search' => 'Institusi Majlis Terdekat'],
    ]);

    $listedInstitution = collect($component->instance()->institutions->items())
        ->firstWhere('id', $institution->id);
    $listedInstitutionDate = CarbonImmutable::parse(
        (string) data_get($listedInstitution, 'next_event_starts_at'),
        'UTC',
    );

    $component
        ->assertSee('data-next-event', false)
        ->assertSee('href="'.route('events.show', $nearestEvent).'"', false)
        ->assertSee(__('Next event'))
        ->assertSee(UserDateTimeFormatter::translatedFormat($listedInstitutionDate, 'j M'))
        ->assertDontSee(UserDateTimeFormatter::translatedFormat($listedInstitutionDate, 'j M Y'))
        ->assertSee('Majlis Institusi Terdekat')
        ->assertDontSee('Majlis Institusi Lebih Lewat');

    expect($listedInstitution)->not->toBeNull()
        ->and($listedInstitution?->next_event_slug)->toBe($nearestEvent->slug)
        ->and($listedInstitution?->next_event_title)->toBe('Majlis Institusi Terdekat')
        ->and($listedInstitution?->next_event_starts_at)->not->toBeNull();
});

it('ignores non-string filter values instead of failing', function () {
    Livewire::test('pages.institutions.results')
        ->call('syncFilters', ['search' => ['nested'], 'country' => ['nested'], 'state' => 123])
        ->assertSet('search', null)
        ->assertSet('country', null)
        ->assertSet('state', null);
});
