<?php

use App\Models\State;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

function ensureVenueIndexMalaysiaCountryExists(): int
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

it('renders the public venue index hero and search copy', function () {
    app()->setLocale('ms');

    get('/tempat')
        ->assertSuccessful()
        ->assertSee(__('Places for'))
        ->assertSee(__('Knowledge & Community'))
        ->assertSee(__('Search venues...'));
});

it('searches public verified venues by name', function () {
    Venue::factory()->create([
        'name' => 'Dewan Riyadhus Solihin',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Venue::factory()->create([
        'name' => 'Auditorium Hikmah',
        'status' => 'verified',
        'is_active' => true,
    ]);

    get('/tempat?search='.urlencode('riyadhus'))
        ->assertSuccessful()
        ->assertSee('Dewan Riyadhus Solihin')
        ->assertDontSee('Auditorium Hikmah');
});

it('only lists active verified venues on the public index', function () {
    Venue::factory()->create([
        'name' => 'Tempat Sah Paparan',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Venue::factory()->create([
        'name' => 'Tempat Menunggu Semakan',
        'status' => 'pending',
        'is_active' => true,
    ]);

    Venue::factory()->create([
        'name' => 'Tempat Tidak Aktif',
        'status' => 'verified',
        'is_active' => false,
    ]);

    get('/tempat')
        ->assertSuccessful()
        ->assertSee('Tempat Sah Paparan')
        ->assertDontSee('Tempat Menunggu Semakan')
        ->assertDontSee('Tempat Tidak Aktif');
});

it('filters venues by selected state', function () {
    $countryId = ensureVenueIndexMalaysiaCountryExists();

    $shownState = State::query()->create([
        'country_id' => $countryId,
        'name' => 'Negeri Tempat Paparan',
        'country_code' => 'MY',
    ]);

    $hiddenState = State::query()->create([
        'country_id' => $countryId,
        'name' => 'Negeri Tempat Tersembunyi',
        'country_code' => 'MY',
    ]);

    $shownVenue = Venue::factory()->create([
        'name' => 'Dewan Negeri Terpilih',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $shownVenue->address()->update([
        'country_id' => $countryId,
        'state_id' => $shownState->id,
    ]);

    $hiddenVenue = Venue::factory()->create([
        'name' => 'Dewan Negeri Lain',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $hiddenVenue->address()->update([
        'country_id' => $countryId,
        'state_id' => $hiddenState->id,
    ]);

    get('/tempat?state_id='.$shownState->id)
        ->assertSuccessful()
        ->assertSee('Dewan Negeri Terpilih')
        ->assertDontSee('Dewan Negeri Lain');
});

it('shows the venue empty state and clear icon button', function () {
    get('/tempat?search=zzzzzzzzz')
        ->assertSuccessful()
        ->assertSee(__('No venues found'))
        ->assertSee(__('We couldn\'t find any venues matching your search or location filters.'))
        ->assertSee('aria-label="Clear search"', false);
});

it('shows the total venue count at the bottom of the index', function () {
    $searchPrefix = 'Jumlah Tempat Ujian';

    Venue::factory()->count(2)->create([
        'name' => $searchPrefix,
        'status' => 'verified',
        'is_active' => true,
    ]);

    get('/tempat?search='.urlencode($searchPrefix))
        ->assertSuccessful()
        ->assertSee('Direktori Tempat')
        ->assertSee('Jumlah tempat: 2');
});
