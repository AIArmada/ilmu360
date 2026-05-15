<?php

use App\Enums\ContributionSubjectType;
use App\Livewire\Pages\Contributions\SubmitInstitution;
use App\Models\ContributionRequest;
use App\Models\District;
use App\Models\Event;
use App\Models\Institution;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\User;
use App\Support\Search\InstitutionSearchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\get;

function ensureMalaysiaCountryExists(): int
{
    $malaysiaId = DB::table('countries')->where('id', 132)->value('id');

    if (is_int($malaysiaId)) {
        return $malaysiaId;
    }

    return DB::table('countries')->insertGetId([
        'id' => 132,
        'iso2' => 'MY',
        'name' => 'Malaysia',
        'status' => 1,
        'phone_code' => '60',
        'iso3' => 'MYS',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);
}

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
        'is_active' => true,
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
        'is_active' => true,
    ]);

    get('/institusi?search='.urlencode('Institusi Kad Tanpa Butiran'))
        ->assertSuccessful()
        ->assertSee('Institusi Kad Tanpa Butiran')
        ->assertSee('aspect-video bg-slate-50', false)
        ->assertSee('border-t border-slate-100 flex items-center justify-center', false)
        ->assertDontSee(__('View Details'));
});

it('renders the institution logo fallback image on cards when no cover exists', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'name' => 'Institusi Logo Kad',
        'status' => 'verified',
        'is_active' => true,
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
        'is_active' => true,
    ]);

    $secondAlphabetical = Institution::factory()->create([
        'name' => 'Zaid Institusi Rawak',
        'status' => 'verified',
        'is_active' => true,
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
    ensureMalaysiaCountryExists();

    $user = User::factory()->create();
    $institutionName = 'Institusi Cadangan Baru';

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', $institutionName)
        ->set('data.type', 'masjid')
        ->set('data.address.google_maps_url', 'https://maps.google.com/?q=3.1390,101.6869')
        ->set('data.address.google_place_id', 'place_123')
        ->set('data.address.lat', 3.1390)
        ->set('data.address.lng', 101.6869)
        ->call('submit')
        ->assertRedirect(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Institution->publicRouteSegment()]))
        ->assertHasNoErrors();

    expect(session('contribution_submission_name'))->toBe($institutionName);

    $institution = Institution::query()
        ->with('address')
        ->where('name', $institutionName)
        ->first();

    expect($institution)->not->toBeNull()
        ->and($institution?->status)->toBe('pending')
        ->and($institution?->addressModel?->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=3.139%2C101.6869&query_place_id=place_123')
        ->and($institution?->addressModel?->google_place_id)->toBe('place_123')
        ->and(abs(((float) $institution?->addressModel?->lat) - 3.1390))->toBeLessThan(0.000001)
        ->and(abs(((float) $institution?->addressModel?->lng) - 101.6869))->toBeLessThan(0.000001);
});

it('rejects duplicate institution submissions when name and locality all match', function () {
    $countryId = ensureMalaysiaCountryExists();
    $user = User::factory()->create();

    $stateId = DB::table('states')->insertGetId([
        'country_id' => $countryId,
        'name' => 'Negeri Ujian Pendua Institusi',
        'country_code' => 'MY',
    ]);
    $state = State::query()->findOrFail($stateId);

    $district = District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Daerah Ujian Pendua Institusi',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Mukim Ujian Pendua Institusi',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Huda Pendua',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $institution->address()->update([
        'country_id' => $countryId,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Masjid   Al-Huda   Pendua')
        ->set('data.type', 'masjid')
        ->set('data.address.country_id', $countryId)
        ->set('data.address.state_id', (int) $state->id)
        ->set('data.address.district_id', (int) $district->id)
        ->set('data.address.subdistrict_id', (int) $subdistrict->id)
        ->set('data.address.google_maps_url', 'https://maps.google.com/?q=3.1390,101.6869')
        ->set('data.address.google_place_id', 'place_duplicate_institution')
        ->set('data.address.lat', 3.1390)
        ->set('data.address.lng', 101.6869)
        ->call('submit')
        ->assertHasErrors(['data.name']);

    expect(Institution::query()->where('name', 'Masjid Al-Huda Pendua')->count())->toBe(1)
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('supports fuzzy search with minor institution name typos', function () {
    Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Institution::factory()->create([
        'name' => 'Pusat Pengajian An-Nur',
        'status' => 'verified',
        'is_active' => true,
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
        'is_active' => true,
    ]);

    Institution::factory()->create([
        'name' => 'Masjid As Shariff',
        'status' => 'verified',
        'is_active' => true,
    ]);

    get('/institusi?search=saiffil')
        ->assertSuccessful()
        ->assertSee(__('No institutions found'))
        ->assertSee(__('We couldn\'t find any institutions matching your search.'))
        ->assertDontSee('Masjid Al Syariff')
        ->assertDontSee('Masjid As Shariff')
        ->assertDontSee('Jumlah institusi:');
});

it('matches institution nicknames on the institution index search', function () {
    Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'status' => 'verified',
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
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'status' => 'verified',
        'is_active' => true,
    ]);

    expect($searchService->publicSearchIds('biru'))
        ->toContain((string) $institution->id);

    $institution->update([
        'nickname' => 'Masjid Hijau',
    ]);

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
    $state = State::where('country_code', 'MY')->first();

    if (! $state) {
        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);

        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => 'Selangor',
            'country_code' => 'MY',
        ]);

        $state = State::query()->findOrFail($stateId);
    }

    $district = District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Petaling',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Shah Alam',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    $institution->address()->update([
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

    get('/institusi?search=Hidayah')
        ->assertSuccessful()
        ->assertSee('Shah Alam, Petaling, Selangor')
        ->assertDontSee(__('Negeri').':')
        ->assertDontSee(__('Daerah').':')
        ->assertDontSee(__('Bandar / Mukim / Zon').':');
});

it('deduplicates matching district and subdistrict labels on institution cards', function () {
    $state = State::query()
        ->where('country_code', 'MY')
        ->where('name', 'Pahang')
        ->first();

    if (! $state) {
        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);

        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => 'Pahang',
            'country_code' => 'MY',
        ]);

        $state = State::query()->findOrFail($stateId);
    }

    $district = District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Temerloh',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Temerloh',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Temerloh',
        'status' => 'verified',
    ]);

    $institution->address()->update([
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

    get('/institusi?search=Temerloh')
        ->assertSuccessful()
        ->assertSee('Temerloh, Pahang')
        ->assertDontSee('Temerloh, Temerloh, Pahang');
});

it('shows location scope controls on institution index without a country selector', function () {
    get('/institusi')
        ->assertSuccessful()
        ->assertDontSeeHtml('id="institution-country-filter"')
        ->assertSee(__('Semua Negeri'))
        ->assertSee(__('Semua Daerah'))
        ->assertSee(__('Semua Bandar / Mukim / Zon'));
});

it('filters institutions by country', function () {
    $malaysiaId = DB::table('countries')->where('id', 132)->value('id');

    if (! $malaysiaId) {
        $malaysiaId = DB::table('countries')->insertGetId([
            'id' => 132,
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);
    }

    $indonesiaId = DB::table('countries')->where('iso2', 'ID')->value('id');

    if (! $indonesiaId) {
        $indonesiaId = DB::table('countries')->insertGetId([
            'iso2' => 'ID',
            'name' => 'Indonesia',
            'status' => 1,
            'phone_code' => '62',
            'iso3' => 'IDN',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);
    }

    $malaysiaStateId = DB::table('states')->insertGetId([
        'country_id' => $malaysiaId,
        'name' => 'Selangor',
        'country_code' => 'MY',
    ]);

    $indonesiaStateId = DB::table('states')->insertGetId([
        'country_id' => $indonesiaId,
        'name' => 'DKI Jakarta',
        'country_code' => 'ID',
    ]);

    $malaysiaInstitution = Institution::factory()->create([
        'name' => 'Institusi Malaysia',
        'status' => 'verified',
    ]);
    $malaysiaInstitution->address()->update([
        'country_id' => (int) $malaysiaId,
        'state_id' => (int) $malaysiaStateId,
        'district_id' => null,
        'subdistrict_id' => null,
    ]);

    $indonesiaInstitution = Institution::factory()->create([
        'name' => 'Institusi Indonesia',
        'status' => 'verified',
    ]);
    $indonesiaInstitution->address()->update([
        'country_id' => (int) $indonesiaId,
        'state_id' => (int) $indonesiaStateId,
        'district_id' => null,
        'subdistrict_id' => null,
    ]);

    get('/institusi?country_id='.$malaysiaId)
        ->assertSuccessful()
        ->assertSee('Institusi Malaysia')
        ->assertDontSee('Institusi Indonesia');
});

it('defaults institutions country filter from an unencrypted browser timezone cookie', function () {
    $malaysiaId = DB::table('countries')->where('id', 132)->value('id');

    if (! $malaysiaId) {
        $malaysiaId = DB::table('countries')->insertGetId([
            'id' => 132,
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);
    }

    $response = $this
        ->withUnencryptedCookie('user_timezone', 'Asia/Jakarta')
        ->get('/institusi');

    $response->assertSuccessful()
        ->assertSee('&quot;country_id&quot;:&quot;132&quot;', false);
});

it('filters institutions by negeri, daerah, and subdistrict scopes', function () {
    $stateA = State::where('country_code', 'MY')->first();

    if (! $stateA) {
        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);

        $stateAId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => 'Selangor',
            'country_code' => 'MY',
        ]);

        $stateA = State::query()->findOrFail($stateAId);
    }

    $stateBId = DB::table('states')->insertGetId([
        'country_id' => (int) $stateA->country_id,
        'name' => 'Negeri Ujian B',
        'country_code' => 'MY',
    ]);
    $stateB = State::query()->findOrFail($stateBId);

    $districtA = District::query()->create([
        'country_id' => (int) $stateA->country_id,
        'state_id' => (int) $stateA->id,
        'country_code' => 'MY',
        'name' => 'Daerah Ujian A',
    ]);

    $districtA2 = District::query()->create([
        'country_id' => (int) $stateA->country_id,
        'state_id' => (int) $stateA->id,
        'country_code' => 'MY',
        'name' => 'Daerah Ujian A2',
    ]);

    $districtB = District::query()->create([
        'country_id' => (int) $stateB->country_id,
        'state_id' => (int) $stateB->id,
        'country_code' => 'MY',
        'name' => 'Daerah Ujian B',
    ]);

    $subdistrictA = Subdistrict::query()->create([
        'country_id' => (int) $stateA->country_id,
        'state_id' => (int) $stateA->id,
        'district_id' => (int) $districtA->id,
        'country_code' => 'MY',
        'name' => 'Mukim Ujian A',
    ]);

    $subdistrictA2 = Subdistrict::query()->create([
        'country_id' => (int) $stateA->country_id,
        'state_id' => (int) $stateA->id,
        'district_id' => (int) $districtA2->id,
        'country_code' => 'MY',
        'name' => 'Mukim Ujian A2',
    ]);

    $subdistrictB = Subdistrict::query()->create([
        'country_id' => (int) $stateB->country_id,
        'state_id' => (int) $stateB->id,
        'district_id' => (int) $districtB->id,
        'country_code' => 'MY',
        'name' => 'Mukim Ujian B',
    ]);

    $institutionA = Institution::factory()->create([
        'name' => 'Institusi Scope A',
        'status' => 'verified',
    ]);
    $institutionA->address()->update([
        'state_id' => (int) $stateA->id,
        'district_id' => (int) $districtA->id,
        'subdistrict_id' => (int) $subdistrictA->id,
    ]);

    $institutionA2 = Institution::factory()->create([
        'name' => 'Institusi Scope A2',
        'status' => 'verified',
    ]);
    $institutionA2->address()->update([
        'state_id' => (int) $stateA->id,
        'district_id' => (int) $districtA2->id,
        'subdistrict_id' => (int) $subdistrictA2->id,
    ]);

    $institutionB = Institution::factory()->create([
        'name' => 'Institusi Scope B',
        'status' => 'verified',
    ]);
    $institutionB->address()->update([
        'state_id' => (int) $stateB->id,
        'district_id' => (int) $districtB->id,
        'subdistrict_id' => (int) $subdistrictB->id,
    ]);

    get('/institusi?state_id='.$stateA->id)
        ->assertSuccessful()
        ->assertSee('Institusi Scope A')
        ->assertSee('Institusi Scope A2')
        ->assertDontSee('Institusi Scope B');

    get('/institusi?state_id='.$stateA->id.'&district_id='.$districtA->id)
        ->assertSuccessful()
        ->assertSee('Institusi Scope A')
        ->assertDontSee('Institusi Scope A2')
        ->assertDontSee('Institusi Scope B');

    get('/institusi?state_id='.$stateA->id.'&district_id='.$districtA->id.'&subdistrict_id='.$subdistrictA->id)
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
        'is_active' => true,
        'starts_at' => now()->addDays(1),
        'published_at' => now(),
    ]);

    Event::factory()->for($institution)->create([
        'title' => 'Pending Event',
        'status' => 'pending',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addDays(1),
        'published_at' => null,
    ]);

    get('/institusi?search=Kiraan')
        ->assertSuccessful()
        ->assertSee('Institusi Kiraan Acara')
        ->assertSee('2 '.__('Events'))
        ->assertDontSee('1 '.__('Events'));
});
