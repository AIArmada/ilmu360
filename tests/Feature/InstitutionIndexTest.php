<?php

use App\Enums\ContributionSubjectType;
use App\Enums\InstitutionNameType;
use App\Livewire\Pages\Contributions\SubmitInstitution;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Support\Search\InstitutionSearchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\get;

it('renders translated hero and search copy on institution index', function () {
    app()->setLocale('ms');

    get('/institusi')
        ->assertSuccessful()
        ->assertSee(__('Centers of'))
        ->assertSee(__('Knowledge & Community'))
        ->assertSee(__('Search institutions...'));
});

it('shows an icon clear button when the institution search has a query', function () {
    get('/institusi?search='.urlencode('bitu'))
        ->assertSuccessful()
        ->assertSee('aria-label="Clear search"', false)
        ->assertSee('M6 6l8 8M14 6l-8 8', false);
});

it('keeps the default country neutral and the filter copy minimal', function () {
    get('/institusi')
        ->assertSuccessful()
        ->assertSee('institution-search-hint', false)
        ->assertDontSee(__('Narrow the directory by area.'))
        ->assertDontSee(__('Start with a state or city to narrow the results.'))
        ->assertDontSee(__('Clear Location Scope'));
});

it('renders translated no-result copy on institution index', function () {
    app()->setLocale('ms');

    get('/institusi?search=zzzzzzzzz')
        ->assertSuccessful()
        ->assertSee(__('No institutions found'))
        ->assertSee(__('We couldn\'t find any institutions matching your search.'));
});

it('shows add-missing-institution call to action on institution index', function () {
    get('/institusi')
        ->assertSuccessful()
        ->assertSee('Tak jumpa institusi yang anda cari? Cadangkan institusi baharu.')
        ->assertSee('Cadangkan institusi baharu');
});

it('shows the total institution count at the bottom of the institution index', function () {
    $searchPrefix = 'Jumlah Institusi Ujian';

    Institution::factory()->count(2)->create([
        'name' => $searchPrefix,
        'status' => 'verified',
    ]);

    get('/institusi?search='.urlencode($searchPrefix))
        ->assertSuccessful()
        ->assertSee('Direktori Institusi')
        ->assertSee('Jumlah institusi: 2');
});

it('centers the institution card majlis counter without a view details label', function () {
    Institution::factory()->create([
        'name' => 'Institusi Kad Tanpa Butiran',
        'status' => 'verified',
    ]);

    get('/institusi?search='.urlencode('Institusi Kad Tanpa Butiran'))
        ->assertSuccessful()
        ->assertSee('Institusi Kad Tanpa Butiran')
        ->assertSee('institution-card-media aspect-video', false)
        ->assertSee('border-t border-slate-100 flex items-center justify-center', false)
        ->assertDontSee(__('View Details'));
});

it('renders the institution logo fallback image on cards when no cover exists', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'name' => 'Institusi Logo Kad',
        'status' => 'verified',
    ]);

    $institution->addMedia(UploadedFile::fake()->image('logo.png', 400, 400))
        ->toMediaCollection('logo');

    get('/institusi?search='.urlencode('Institusi Logo Kad'))
        ->assertSuccessful()
        ->assertSee($institution->public_image_url, false);
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

    $component = Livewire::test('pages.institutions.index');

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

it('redirects guests to login when opening add institution form', function () {
    get(route('contributions.submit-institution'))
        ->assertRedirect(route('login'));
});

it('allows users to submit a missing institution from institution index with pending status', function () {
    $country = ensureTestMalaysiaCountry();

    $user = User::factory()->create();
    $institutionName = 'Institusi Cadangan Baru';

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', $institutionName)
        ->set('data.type', 'masjid')
        ->set('data.address.country_id', $country->getKey())
        ->set('data.address.google_maps_url', 'https://maps.google.com/?q=3.1390,101.6869')
        ->set('data.address.provider_place_id', 'place_123')
        ->set('data.address.latitude', 3.1390)
        ->set('data.address.longitude', 101.6869)
        ->call('submit')
        ->assertRedirect(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Institution->publicRouteSegment()]))
        ->assertHasNoErrors();

    expect(session('contribution_submission_name'))->toBe($institutionName);

    $institution = Institution::query()
        ->with('addresses')
        ->where('name', $institutionName)
        ->first();

    expect($institution)->not->toBeNull()
        ->and($institution?->status)->toBe('pending')
        ->and($institution?->primaryAddress()?->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=3.139%2C101.6869&query_place_id=place_123')
        ->and($institution?->primaryAddress()?->google_place_id)->toBe('place_123')
        ->and(abs(((float) $institution?->primaryAddress()?->lat) - 3.1390))->toBeLessThan(0.000001)
        ->and(abs(((float) $institution?->primaryAddress()?->lng) - 101.6869))->toBeLessThan(0.000001);
});

it('rejects duplicate institution submissions when name and locality all match', function () {
    $user = User::factory()->create();
    $geo = createTestPackageGeography(
        'Negeri Ujian Pendua Institusi',
        'Daerah Ujian Pendua Institusi',
        'Mukim Ujian Pendua Institusi',
    );

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Huda Pendua',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($institution, $geo['address']);

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Masjid   Al-Huda   Pendua')
        ->set('data.type', 'masjid')
        ->set('data.address.country_id', $geo['address']['country_id'])
        ->set('data.address.state_id', $geo['address']['state_id'])
        ->set('data.address.administrative_district_id', $geo['address']['administrative_district_id'])
        ->set('data.address.administrative_subdivision_id', $geo['address']['administrative_subdivision_id'])
        ->set('data.address.google_maps_url', 'https://maps.google.com/?q=3.1390,101.6869')
        ->set('data.address.provider_place_id', 'place_duplicate_institution')
        ->set('data.address.latitude', 3.1390)
        ->set('data.address.longitude', 101.6869)
        ->call('submit')
        ->assertHasErrors(['data.name']);

    expect(Institution::query()->where('name', 'Masjid Al-Huda Pendua')->count())->toBe(1)
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('supports fuzzy search with minor institution name typos', function () {
    Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Pusat Pengajian An-Nur',
        'status' => 'verified',
    ]);

    get('/institusi?search=Hidayh')
        ->assertSuccessful()
        ->assertSee('Masjid Al Hidayah')
        ->assertDontSee('Pusat Pengajian An-Nur');
});

it('shows the empty state when institution search only has unrelated fuzzy candidates', function () {
    Institution::factory()->create([
        'name' => 'Masjid Al Syariff',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Masjid As Shariff',
        'status' => 'verified',
    ]);

    get('/institusi?search=saiffil')
        ->assertSuccessful()
        ->assertSee(__('No institutions found'))
        ->assertSee(__('We couldn\'t find any institutions matching your search.'))
        ->assertDontSee('Masjid Al Syariff')
        ->assertDontSee('Masjid As Shariff')
        ->assertDontSee('Jumlah institusi:');
});

it('matches institution alternative names on the institution index search', function () {
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

    Institution::factory()->create([
        'name' => 'Masjid Negara',
        'status' => 'verified',
    ]);

    get('/institusi?search=Masjid+Biru')
        ->assertSuccessful()
        ->assertSee('Masjid Sultan Salahuddin Abdul Aziz Shah')
        ->assertDontSee('Masjid Negara');
});

it('keeps multi-word search strict to phrase-relevant institutions', function () {
    Institution::factory()->create([
        'name' => 'Masjid Besi Putrajaya',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Pusat Komuniti Besi',
        'status' => 'verified',
    ]);

    get('/institusi?search=masjid+besi')
        ->assertSuccessful()
        ->assertSee('Masjid Besi Putrajaya')
        ->assertDontSee('Masjid Al Hidayah')
        ->assertDontSee('Pusat Komuniti Besi');
});

it('updates institution results live when search changes', function () {
    Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Pusat Pengajian An-Nur',
        'status' => 'verified',
    ]);

    Livewire::test('pages.institutions.index')
        ->set('search', 'Hidayh')
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

    $updatedSearchResults = Livewire::test('pages.institutions.index')
        ->set('search', 'hijau')
        ->instance()
        ->institutions;

    expect(collect($updatedSearchResults->items())->pluck('id')->all())
        ->toContain((string) $institution->id);
});

it('shows location hierarchy values without labels on institution cards', function () {
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($institution, $geo['address']);

    get('/institusi?search=Hidayah')
        ->assertSuccessful()
        ->assertSee('Shah Alam, Petaling, Selangor')
        ->assertDontSee(__('Negeri').':')
        ->assertDontSee(__('Daerah').':')
        ->assertDontSee(__('Bandar / Mukim / Zon').':');
});

it('deduplicates matching district and subdistrict labels on institution cards', function () {
    $geo = createTestPackageGeography('Pahang', 'Temerloh', 'Temerloh');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Temerloh',
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($institution, $geo['address']);

    get('/institusi?search=Temerloh')
        ->assertSuccessful()
        ->assertSee('Temerloh, Pahang')
        ->assertDontSee('Temerloh, Temerloh, Pahang');
});

it('defaults the institution location scope to the application country', function () {
    get('/institusi')
        ->assertSuccessful()
        ->assertSee('id="institution-country-filter"', false)
        ->assertSee(__('All countries'));

    Livewire::test('pages.institutions.index')
        ->assertSet('country_id', ensureTestMalaysiaCountry()->getKey());
});

it('follows the Malaysia geography cascade without exposing a city filter', function () {
    $geo = createTestPackageGeography('Selangor Cascade', 'Petaling Cascade', 'Subang Cascade');

    Livewire::test('pages.institutions.index')
        ->assertDontSee('institution-city-filter', false)
        ->set('state_id', $geo['state']->getKey())
        ->assertSee('institution-district-filter', false)
        ->assertDontSee('institution-city-filter', false);
});

it('filters institutions by country', function () {
    $malaysia = ensureTestMalaysiaCountry();
    $indonesia = ensureTestAddressCountry(
        iso2: 'ID',
        name: 'Indonesia',
        iso3: 'IDN',
        timezones: ['Asia/Jakarta'],
        phoneCode: '62',
    );

    $malaysiaInstitution = Institution::factory()->create([
        'name' => 'Institusi Malaysia',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($malaysiaInstitution, [
        'country_id' => (string) $malaysia->getKey(),
    ]);

    $indonesiaInstitution = Institution::factory()->create([
        'name' => 'Institusi Indonesia',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($indonesiaInstitution, [
        'country_id' => (string) $indonesia->getKey(),
    ]);

    get('/institusi?country_id='.$malaysia->getKey())
        ->assertSuccessful()
        ->assertSee('Institusi Malaysia')
        ->assertDontSee('Institusi Indonesia');
});

it('does not infer the institution country from an unencrypted browser timezone cookie', function () {
    Livewire::withCookie('user_timezone', 'Asia/Jakarta')
        ->test('pages.institutions.index')
        ->assertSet('country_id', ensureTestMalaysiaCountry()->getKey())
        ->assertSet('state_id', null)
        ->assertSet('city_id', null)
        ->assertSet('administrative_district_id', null)
        ->assertSet('administrative_subdivision_id', null);
});

it('allows the institution directory to clear the country scope for international search', function () {
    Livewire::test('pages.institutions.index')
        ->call('clearFilters')
        ->assertSet('country_id', null)
        ->assertSet('state_id', null)
        ->assertSet('city_id', null)
        ->assertSet('administrative_district_id', null)
        ->assertSet('administrative_subdivision_id', null)
        ->assertDontSee('institution-state-filter')
        ->assertDontSee('institution-city-filter')
        ->assertDontSee('institution-district-filter')
        ->assertDontSee('institution-subdistrict-filter');
});

it('filters institutions by the package city level', function () {
    $geo = createTestPackageGeography('Negeri City Filter', 'Daerah City Filter', 'Mukim City Filter', 'Bandar City Filter');

    $matching = Institution::factory()->create([
        'name' => 'Institusi Bandar City Filter',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($matching, $geo['address']);

    $other = Institution::factory()->create([
        'name' => 'Institusi Negeri City Filter',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($other, [
        ...$geo['address'],
        'city_id' => null,
    ]);

    get('/institusi?state_id='.$geo['state']->getKey().'&city_id='.$geo['city']->getKey())
        ->assertSuccessful()
        ->assertSee('Institusi Bandar City Filter')
        ->assertDontSee('Institusi Negeri City Filter');
});

it('filters institutions by negeri, daerah, and subdistrict scopes', function () {
    $geoA = createTestPackageGeography('Selangor Scope A', 'Daerah Ujian A', 'Mukim Ujian A');
    $districtA2 = createTestAddressArea('Daerah Ujian A2', 2, parent: $geoA['area_tree_root'], country: $geoA['country']);
    $subdistrictA2 = createTestAddressArea('Mukim Ujian A2', 3, parent: $districtA2, country: $geoA['country']);
    $geoB = createTestPackageGeography('Negeri Ujian B', 'Daerah Ujian B', 'Mukim Ujian B');

    $institutionA = Institution::factory()->create([
        'name' => 'Institusi Scope A',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($institutionA, $geoA['address']);

    $institutionA2 = Institution::factory()->create([
        'name' => 'Institusi Scope A2',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($institutionA2, [
        ...$geoA['address'],
        'administrative_district_id' => (string) $districtA2->getKey(),
        'administrative_subdivision_id' => (string) $subdistrictA2->getKey(),
    ]);

    $institutionB = Institution::factory()->create([
        'name' => 'Institusi Scope B',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($institutionB, $geoB['address']);

    get('/institusi?state_id='.$geoA['state']->getKey())
        ->assertSuccessful()
        ->assertSee('Institusi Scope A')
        ->assertSee('Institusi Scope A2')
        ->assertDontSee('Institusi Scope B');

    get('/institusi?state_id='.$geoA['state']->getKey().'&administrative_district_id='.$geoA['district']->getKey())
        ->assertSuccessful()
        ->assertSee('Institusi Scope A')
        ->assertDontSee('Institusi Scope A2')
        ->assertDontSee('Institusi Scope B');

    get('/institusi?state_id='.$geoA['state']->getKey().'&administrative_district_id='.$geoA['district']->getKey().'&administrative_subdivision_id='.$geoA['subdistrict']->getKey())
        ->assertSuccessful()
        ->assertSee('Institusi Scope A')
        ->assertDontSee('Institusi Scope A2')
        ->assertDontSee('Institusi Scope B');
});

it('counts approved and pending public active events on institution cards', function () {
    $institution = Institution::factory()->create([
        'name' => 'Institusi Kiraan Acara',
        'slug' => 'institusi-kiraan-acara',
        'status' => 'verified',
    ]);

    Event::factory()->for($institution)->create([
        'title' => 'Approved Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(1),
        'published_at' => now(),
    ]);

    Event::factory()->for($institution)->create([
        'title' => 'Pending Event',
        'status' => 'pending',
        'visibility' => 'public',
        'starts_at' => now()->addDays(1),
        // Public listing counts use published_at (not legacy is_active).
        'published_at' => now(),
    ]);

    get('/institusi?search=Kiraan')
        ->assertSuccessful()
        ->assertSee('Institusi Kiraan Acara')
        ->assertSee('2 '.__('Events'))
        ->assertDontSee('1 '.__('Events'));
});
