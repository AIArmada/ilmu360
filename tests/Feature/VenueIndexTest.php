<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use App\Models\Venue;
use Illuminate\Support\Str;

use function Pest\Laravel\get;

function ensureVenueIndexMalaysiaCountryExists(): AddressCountry
{
    $country = AddressCountry::query()->where('iso2', 'MY')->first();

    if ($country instanceof AddressCountry) {
        return $country;
    }

    return AddressCountry::query()->create([
        'name' => 'Malaysia',
        'iso2' => 'MY',
        'iso3' => 'MYS',
        'entity_type' => 'country',
        'phone_code' => '60',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
        'timezones' => ['Asia/Kuala_Lumpur'],
    ]);
}

function createVenueIndexState(AddressCountry $country, string $name): AddressArea
{
    return AddressArea::query()->create([
        'country_id' => $country->id,
        'parent_id' => null,
        'country_code' => $country->iso2,
        'level' => 1,
        'name' => $name,
        'type' => 'state',
        'slug' => Str::slug($name),
        'source' => 'tests',
        'source_id' => 'venue-index-state-'.Str::slug($name).'-'.Str::lower(Str::random(6)),
    ]);
}

function updateVenueIndexPrimaryAddress(Venue $venue, array $attributes): void
{
    $address = $venue->addressModel;

    if (! $address instanceof Address) {
        $address = Address::query()->create([
            'country_code' => (string) ($attributes['country_code'] ?? 'MY'),
        ]);

        $venue->attachAddress($address, 'primary', true);
    }

    $address->update($attributes);
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
    ]);

    Venue::factory()->create([
        'name' => 'Auditorium Hikmah',
        'status' => 'verified',
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
    ]);

    Venue::factory()->create([
        'name' => 'Tempat Menunggu Semakan',
        'status' => 'pending',
    ]);

    Venue::factory()->create([
        'name' => 'Tempat Tidak Aktif',
        'status' => 'inactive',
    ]);

    get('/tempat')
        ->assertSuccessful()
        ->assertSee('Tempat Sah Paparan')
        ->assertDontSee('Tempat Menunggu Semakan')
        ->assertDontSee('Tempat Tidak Aktif');
});

it('filters venues by selected state', function () {
    $shown = createTestPackageGeography('Negeri Tempat Paparan', 'Daerah Tempat Paparan', 'Mukim Tempat Paparan');
    $hidden = createTestPackageGeography('Negeri Tempat Tersembunyi', 'Daerah Tempat Tersembunyi', 'Mukim Tempat Tersembunyi');

    $shownVenue = Venue::factory()->create([
        'name' => 'Dewan Negeri Terpilih',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($shownVenue, $shown['address']);

    $hiddenVenue = Venue::factory()->create([
        'name' => 'Dewan Negeri Lain',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($hiddenVenue, $hidden['address']);

    get('/tempat?state_id='.$shown['state']->getKey())
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
    ]);

    get('/tempat?search='.urlencode($searchPrefix))
        ->assertSuccessful()
        ->assertSee('Direktori Tempat')
        ->assertSee('Jumlah tempat: 2');
});
