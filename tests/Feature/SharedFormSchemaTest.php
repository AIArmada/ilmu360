<?php

use App\Filament\Resources\Institutions\Schemas\InstitutionForm as AdminInstitutionForm;
use App\Filament\Resources\References\Schemas\ReferenceForm as AdminReferenceForm;
use App\Filament\Resources\Speakers\Schemas\SpeakerForm as AdminSpeakerForm;
use App\Filament\Resources\Venues\Schemas\VenueForm as AdminVenueForm;
use App\Forms\InstitutionContributionFormSchema;
use App\Forms\InstitutionFormSchema;
use App\Forms\SharedFormSchema;
use App\Forms\SpeakerContributionFormSchema;
use App\Forms\SpeakerFormSchema;
use App\Forms\VenueFormSchema;
use App\Models\Country;
use App\Models\District;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Venue;
use App\Support\Location\FederalTerritoryLocation;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Component as LivewireComponent;
use Nnjeim\World\Models\Language;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;

uses(RefreshDatabase::class);

beforeEach(function () {
    FederalTerritoryLocation::flushStateIdCache();

    Country::query()->firstOrCreate(
        ['id' => 132],
        [
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ],
    );
});

it('creates an address when only a google maps url is provided', function () {
    $institution = Institution::factory()->create();
    $institution->address()->delete();

    SharedFormSchema::createAddressFromData($institution, [
        'country_id' => 132,
        'google_maps_url' => 'https://www.google.com/maps/@3.139003,101.686855,17z',
    ]);

    $address = $institution->fresh()->addressModel;

    expect($address)->not->toBeNull();
    expect($address?->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=3.139003%2C101.686855');
    expect(abs(((float) $address?->lat) - 3.139003))->toBeLessThan(0.000001);
    expect(abs(((float) $address?->lng) - 101.686855))->toBeLessThan(0.000001);
});

it('preserves omitted address geo fields when preparing partial update payloads', function () {
    $prepared = SharedFormSchema::prepareAddressPersistenceData([
        'country_id' => 132,
        'line1' => 'Jalan Duta',
    ]);

    expect($prepared)->toHaveKey('country_id')
        ->and($prepared)->toHaveKey('line1')
        ->and($prepared)->not->toHaveKey('lat')
        ->and($prepared)->not->toHaveKey('lng')
        ->and($prepared)->not->toHaveKey('google_maps_url')
        ->and($prepared)->not->toHaveKey('google_place_id')
        ->and($prepared)->not->toHaveKey('waze_url');
});

it('preserves explicit google place metadata when creating an address from form data', function () {
    $institution = Institution::factory()->create();
    $institution->address()->delete();

    SharedFormSchema::createAddressFromData($institution, [
        'country_id' => 132,
        'line1' => 'Persiaran Masjid',
        'google_maps_url' => 'https://www.google.com/maps/place/?q=place_id:place_123',
        'google_place_id' => 'place_123',
        'lat' => '3.07853',
        'lng' => '101.52073',
    ]);

    $address = $institution->fresh()->addressModel;

    expect($address)->not->toBeNull();
    expect($address?->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=3.07853%2C101.52073&query_place_id=place_123')
        ->and($address?->google_place_id)->toBe('place_123');
    expect(abs(((float) $address?->lat) - 3.07853))->toBeLessThan(0.000001);
    expect(abs(((float) $address?->lng) - 101.52073))->toBeLessThan(0.000001);
});

it('preserves raw google maps urls without enrichment when normalization is disabled', function () {
    $institution = Institution::factory()->create();
    $institution->address()->delete();

    Http::fake();

    SharedFormSchema::createAddressFromData($institution, [
        'country_id' => 132,
        'google_maps_url' => 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8',
        'google_maps_normalization_enabled' => false,
    ]);

    $address = $institution->fresh()->addressModel;

    expect($address)->not->toBeNull();
    expect($address?->google_maps_url)->toBe('https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8')
        ->and($address?->google_place_id)->toBeNull()
        ->and($address?->lat)->toBeNull()
        ->and($address?->lng)->toBeNull();

    Http::assertNothingSent();
});

it('still canonicalizes google maps urls locally when remote lookup is disabled', function () {
    $institution = Institution::factory()->create();
    $institution->address()->delete();

    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake([
        'https://maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Masjid+Jamik+Ungku+Ahmad,+Kampung+Separap/@1.9089362,102.865462,925m/data=!3m2!1e3!4b1!4m6!3m5!1s0x31d0539173ae7dd9:0xb4fce77c077ec5f3!8m2!3d1.9089362!4d102.865462!16s%2Fg%2F11sqw6yjrc?hl=en-US&entry=ttu',
        ]),
        'https://places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [[
                'id' => 'ChIJ2X2uc5FT0DER88V-B3zn_LQ',
                'displayName' => ['text' => 'Masjid Jamik Ungku Ahmad, Kampung Separap'],
                'location' => [
                    'latitude' => 1.9089362,
                    'longitude' => 102.865462,
                ],
            ]],
        ], 200),
    ]);

    SharedFormSchema::createAddressFromData($institution, [
        'country_id' => 132,
        'google_maps_url' => 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8',
        'google_maps_remote_lookup_enabled' => false,
    ]);

    $address = $institution->fresh()->addressModel;

    expect($address)->not->toBeNull();
    expect($address?->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=1.9089362%2C102.865462')
        ->and($address?->google_place_id)->toBeNull();
    expect(abs(((float) $address?->lat) - 1.9089362))->toBeLessThan(0.000001);
    expect(abs(((float) $address?->lng) - 102.865462))->toBeLessThan(0.000001);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with((string) $request->url(), 'https://maps.app.goo.gl/'));
});

it('loads subdistricts directly from federal territory states and clears district persistence', function () {
    $kualaLumpur = State::query()->create([
        'country_id' => 132,
        'name' => 'Kuala Lumpur',
        'country_code' => 'MY',
    ]);

    $stateSubdistrict = Subdistrict::query()->create([
        'country_id' => 132,
        'state_id' => (int) $kualaLumpur->id,
        'district_id' => null,
        'country_code' => 'MY',
        'name' => 'Setiawangsa',
    ]);

    $legacyDistrict = District::query()->create([
        'country_id' => 132,
        'state_id' => (int) $kualaLumpur->id,
        'country_code' => 'MY',
        'name' => 'Legacy Kuala Lumpur District',
    ]);

    expect(SharedFormSchema::districtOptionsForState($kualaLumpur->id))->toBe([]);
    expect(SharedFormSchema::subdistrictOptionsForSelection($kualaLumpur->id, null))
        ->toBe([(string) $stateSubdistrict->id => 'Setiawangsa']);
    expect(SharedFormSchema::shouldShowSubdistrictField($kualaLumpur->id, null))->toBeTrue();

    $prepared = SharedFormSchema::prepareAddressPersistenceData([
        'state_id' => $kualaLumpur->id,
        'district_id' => $legacyDistrict->id,
        'subdistrict_id' => $stateSubdistrict->id,
    ]);

    expect($prepared['district_id'])->toBeNull()
        ->and($prepared['subdistrict_id'])->toBe((int) $stateSubdistrict->id);
});

it('still requires districts for non federal territory subdistrict selection', function () {
    $selangor = State::query()->create([
        'country_id' => 132,
        'name' => 'Selangor',
        'country_code' => 'MY',
    ]);

    $district = District::query()->create([
        'country_id' => 132,
        'state_id' => (int) $selangor->id,
        'country_code' => 'MY',
        'name' => 'Petaling',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => 132,
        'state_id' => (int) $selangor->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Shah Alam',
    ]);

    expect(SharedFormSchema::districtOptionsForState($selangor->id))
        ->toBe([(string) $district->id => 'Petaling']);
    expect(SharedFormSchema::subdistrictOptionsForSelection($selangor->id, null))->toBe([]);
    expect(SharedFormSchema::subdistrictOptionsForSelection($selangor->id, $district->id))
        ->toBe([(string) $subdistrict->id => 'Shah Alam']);
    expect(SharedFormSchema::shouldShowSubdistrictField($selangor->id, null))->toBeFalse()
        ->and(SharedFormSchema::shouldShowSubdistrictField($selangor->id, $district->id))->toBeTrue();
});

it('does not skip districts for non malaysian states with federal territory names', function () {
    $jakartaKualaLumpur = State::query()->create([
        'country_id' => 360,
        'name' => 'Kuala Lumpur',
        'country_code' => 'ID',
    ]);

    $district = District::query()->create([
        'country_id' => 360,
        'state_id' => (int) $jakartaKualaLumpur->id,
        'country_code' => 'ID',
        'name' => 'Kecamatan Example',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => 360,
        'state_id' => (int) $jakartaKualaLumpur->id,
        'district_id' => (int) $district->id,
        'country_code' => 'ID',
        'name' => 'Kelurahan Example',
    ]);

    expect(FederalTerritoryLocation::isFederalTerritoryStateId($jakartaKualaLumpur->id))->toBeFalse();
    expect(SharedFormSchema::districtOptionsForState($jakartaKualaLumpur->id))
        ->toBe([(string) $district->id => 'Kecamatan Example']);
    expect(SharedFormSchema::subdistrictOptionsForSelection($jakartaKualaLumpur->id, null))->toBe([]);
    expect(SharedFormSchema::subdistrictOptionsForSelection($jakartaKualaLumpur->id, $district->id))
        ->toBe([(string) $subdistrict->id => 'Kelurahan Example']);
    expect(SharedFormSchema::shouldShowSubdistrictField($jakartaKualaLumpur->id, null))->toBeFalse()
        ->and(SharedFormSchema::shouldShowSubdistrictField($jakartaKualaLumpur->id, $district->id))->toBeTrue();
});

it('defaults address country_id to malaysia when null is submitted directly to the model', function () {
    $institution = Institution::factory()->create();

    $address = $institution->addressModel;

    expect($address)->not->toBeNull();

    $address?->fill([
        'country_id' => null,
    ])->save();

    expect($institution->fresh()->addressModel?->country_id)->toBe(132);
});

it('pins hidden country fields in institution and venue public creation forms', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $institutionCountry = collect($flatten(InstitutionFormSchema::createOptionForm(includeLocationPicker: true)))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('country_id');
    $venueCountry = collect($flatten(VenueFormSchema::createOptionForm(includeLocationPicker: true)))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('country_id');
    $contributionCountry = collect($flatten(InstitutionContributionFormSchema::components(
        includeMedia: true,
        requireGoogleMaps: true,
        addressStatePath: 'address',
        includeLocationPicker: true,
    )))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('country_id');

    expect($institutionCountry)->toBeInstanceOf(Hidden::class)
        ->and($venueCountry)->toBeInstanceOf(Hidden::class)
        ->and($contributionCountry)->toBeInstanceOf(Hidden::class);
});

it('requires google maps url in institution and venue quick-create forms', function () {
    $institutionComponents = collect(InstitutionFormSchema::createOptionForm())
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $venueComponents = collect(VenueFormSchema::createOptionForm())
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $institutionGoogleMaps = $institutionComponents->get('google_maps_url');
    $venueGoogleMaps = $venueComponents->get('google_maps_url');

    expect($institutionGoogleMaps)->not->toBeNull();
    expect($venueGoogleMaps)->not->toBeNull();
    expect(method_exists($institutionGoogleMaps, 'isRequired'))->toBeTrue();
    expect(method_exists($venueGoogleMaps, 'isRequired'))->toBeTrue();
    expect($institutionGoogleMaps?->isRequired())->toBeTrue();
    expect($venueGoogleMaps?->isRequired())->toBeTrue();
});

it('hides raw google maps fields in institution and venue quick-create picker mode', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $institutionComponents = collect($flatten(InstitutionFormSchema::createOptionForm(includeLocationPicker: true)));
    $venueComponents = collect($flatten(VenueFormSchema::createOptionForm(includeLocationPicker: true)));

    $institutionGoogleMaps = $institutionComponents
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('google_maps_url');
    $venueGoogleMaps = $venueComponents
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('google_maps_url');

    expect($institutionGoogleMaps)->toBeInstanceOf(Hidden::class)
        ->and($venueGoogleMaps)->toBeInstanceOf(Hidden::class);
    expect($institutionComponents->contains(fn (mixed $component): bool => $component instanceof SchemaView))->toBeTrue();
    expect($venueComponents->contains(fn (mixed $component): bool => $component instanceof SchemaView))->toBeTrue();
});

it('shows raw google maps fields in institution and venue quick-create fallback mode', function () {
    config()->set('services.google.maps_api_key');
    config()->set('services.google.places_enabled', false);

    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $institutionGoogleMaps = collect($flatten(InstitutionFormSchema::createOptionForm(includeLocationPicker: true)))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('google_maps_url');
    $venueGoogleMaps = collect($flatten(VenueFormSchema::createOptionForm(includeLocationPicker: true)))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('google_maps_url');

    expect($institutionGoogleMaps)->toBeInstanceOf(TextInput::class)
        ->and($venueGoogleMaps)->toBeInstanceOf(TextInput::class);
});

it('requires google maps url on the dedicated institution contribution create form', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $components = collect($flatten(InstitutionContributionFormSchema::components(includeMedia: true, requireGoogleMaps: true)))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $googleMaps = $components->get('google_maps_url');

    expect($googleMaps)->not->toBeNull();
    expect(method_exists($googleMaps, 'isRequired'))->toBeTrue();
    expect($googleMaps?->isRequired())->toBeTrue();
});

it('shows the raw google maps field in picker mode while keeping the location requirement', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $components = collect($flatten(InstitutionContributionFormSchema::components(
        includeMedia: true,
        requireGoogleMaps: true,
        addressStatePath: 'address',
        includeLocationPicker: true,
    )))->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $googleMaps = $components->get('google_maps_url');

    expect($googleMaps)->toBeInstanceOf(TextInput::class);
    expect(method_exists($googleMaps, 'isRequired'))->toBeTrue();
    expect($googleMaps?->isRequired())->toBeTrue();
});

it('locks the dedicated institution contribution google maps url after picker selection but keeps manual mode editable', function () {
    $livewire = new class extends LivewireComponent implements HasSchemas
    {
        use InteractsWithSchemas;

        public ?array $data = [];

        public function render(): string
        {
            return '';
        }
    };

    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponents = $reflection->getProperty('childComponents')->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $findGoogleMapsField = (fn (Schema $schema): ?TextInput => collect($flatten($schema->getComponents()))
        ->first(fn (mixed $component): bool => $component instanceof TextInput
            && method_exists($component, 'getName')
            && $component->getName() === 'google_maps_url'));

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components(InstitutionContributionFormSchema::components(
            includeMedia: false,
            requireGoogleMaps: true,
            addressStatePath: 'address',
            includeLocationPicker: true,
        ));

    $schema->fill([
        'address' => [
            'google_maps_url' => 'https://maps.google.com/?q=3.1390,101.6869',
            'google_resolution_source' => 'manual',
        ],
    ]);

    $googleMaps = $findGoogleMapsField($schema);

    expect($googleMaps)->toBeInstanceOf(TextInput::class)
        ->and(method_exists($googleMaps, 'isReadOnly'))->toBeTrue()
        ->and($googleMaps?->isReadOnly())->toBeFalse();

    $schema->fill([
        'address' => [
            'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=3.07853%2C101.52073&query_place_id=place_abc123',
            'google_resolution_source' => 'picker',
        ],
    ]);

    expect($googleMaps?->isReadOnly())->toBeTrue();
});

it('shows the raw google maps field in manual institution contribution mode', function () {
    config()->set('services.google.maps_api_key');
    config()->set('services.google.places_enabled', false);

    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $components = collect($flatten(InstitutionContributionFormSchema::components(
        includeMedia: true,
        requireGoogleMaps: true,
        addressStatePath: 'address',
        includeLocationPicker: true,
    )))->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $googleMaps = $components->get('google_maps_url');

    expect($googleMaps)->toBeInstanceOf(TextInput::class);
    expect(method_exists($googleMaps, 'isRequired'))->toBeTrue();
    expect($googleMaps?->isRequired())->toBeTrue();
});

it('uses rich description and full contact details in the institution quick-create form', function () {
    $components = collect(InstitutionFormSchema::createOptionForm())
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    expect($components->get('nickname'))->toBeInstanceOf(TextInput::class)
        ->and($components->get('description'))->toBeInstanceOf(RichEditor::class)
        ->and($components->get('contacts'))->toBeInstanceOf(Repeater::class)
        ->and($components->has('proposer_note'))->toBeFalse()
        ->and($components->has('logo'))->toBeFalse();
});

it('stores description and contacts when creating an institution via quick-create', function () {
    $institutionId = InstitutionFormSchema::createOptionUsing([
        'name' => 'Masjid Quick Create',
        'nickname' => 'Masjid QC',
        'type' => 'masjid',
        'country_id' => 132,
        'description' => '<p>Institusi komuniti yang aktif.</p>',
        'contacts' => [[
            'category' => 'phone',
            'value' => '0123456789',
            'type' => 'main',
            'is_public' => true,
        ], [
            'category' => 'email',
            'value' => 'contact@masjidqc.test',
            'type' => 'main',
            'is_public' => true,
        ]],
    ]);

    $institution = Institution::query()
        ->with('contacts')
        ->findOrFail($institutionId);

    expect($institution->description)->toBe('<p>Institusi komuniti yang aktif.</p>')
        ->and($institution->nickname)->toBe('Masjid QC')
        ->and($institution->contacts->pluck('value')->all())->toEqual(['0123456789', 'contact@masjidqc.test'])
        ->and($institution->contacts->pluck('order_column')->all())->toEqual([1, 2]);
});

it('uses the phone input for institution phone and whatsapp contact values', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponents = $reflection->getProperty('childComponents')->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $components = collect($flatten(AdminInstitutionForm::configure(Schema::make())->getComponents()))
        ->first(fn (mixed $component): bool => method_exists($component, 'getName') && $component->getName() === 'contacts');

    expect($components)->toBeInstanceOf(Repeater::class);

    $reflection = new ReflectionObject($components);

    while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
        $reflection = $parent;
    }

    $childComponents = $reflection->getProperty('childComponents')->getValue($components);
    $defaultChildComponents = $childComponents['default'] ?? [];
    $phoneValueFields = collect($defaultChildComponents)
        ->filter(fn (mixed $component): bool => method_exists($component, 'getName') && $component->getName() === 'phone_value');
    $emailValueFields = collect($defaultChildComponents)
        ->filter(fn (mixed $component): bool => method_exists($component, 'getName') && $component->getName() === 'value');

    expect($phoneValueFields->contains(fn (mixed $component): bool => $component instanceof PhoneInput))->toBeTrue();
    expect($emailValueFields->contains(fn (mixed $component): bool => $component instanceof TextInput))->toBeTrue();
});

it('configures contact and social media repeaters to persist drag ordering', function () {
    $livewire = new class extends LivewireComponent implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '';
        }
    };

    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponents = $reflection->getProperty('childComponents')->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $findRepeater = (fn (array $components, string $name): ?Repeater => collect($flatten($components))
        ->first(fn (mixed $component): bool => $component instanceof Repeater
            && method_exists($component, 'getName')
            && $component->getName() === $name));

    $institutionSchema = AdminInstitutionForm::configure(Schema::make($livewire))->getComponents();
    $speakerSchema = AdminSpeakerForm::configure(Schema::make($livewire))->getComponents();
    $venueSchema = AdminVenueForm::configure(Schema::make($livewire))->getComponents();
    $referenceSchema = AdminReferenceForm::configure(Schema::make($livewire))->getComponents();

    expect($findRepeater($institutionSchema, 'contacts')?->getOrderColumn())->toBe('order_column')
        ->and($findRepeater($institutionSchema, 'socialMedia')?->getOrderColumn())->toBe('order_column')
        ->and($findRepeater($speakerSchema, 'contacts')?->getOrderColumn())->toBe('order_column')
        ->and($findRepeater($speakerSchema, 'socialMedia')?->getOrderColumn())->toBe('order_column')
        ->and($findRepeater($venueSchema, 'socialMedia')?->getOrderColumn())->toBe('order_column')
        ->and($findRepeater($referenceSchema, 'socialMedia')?->getOrderColumn())->toBe('order_column');
});

it('stores nested institution quick-create address data when picker mode is used', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake([
        'https://maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Masjid+Jamik+Ungku+Ahmad,+Kampung+Separap/@1.9089362,102.865462,925m/data=!3m2!1e3!4b1!4m6!3m5!1s0x31d0539173ae7dd9:0xb4fce77c077ec5f3!8m2!3d1.9089362!4d102.865462!16s%2Fg%2F11sqw6yjrc?hl=en-US&entry=ttu',
        ]),
    ]);

    $institutionId = InstitutionFormSchema::createOptionUsing([
        'name' => 'Masjid Event Quick Create',
        'type' => 'masjid',
        'address' => [
            'country_id' => 132,
            'google_maps_url' => 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8',
            'google_maps_remote_lookup_enabled' => false,
        ],
    ]);

    $institution = Institution::query()
        ->with('address')
        ->findOrFail($institutionId);

    expect($institution->addressModel?->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=1.9089362%2C102.865462')
        ->and($institution->addressModel?->google_place_id)->toBeNull();
    expect(abs(((float) $institution->addressModel?->lat) - 1.9089362))->toBeLessThan(0.000001);
    expect(abs(((float) $institution->addressModel?->lng) - 102.865462))->toBeLessThan(0.000001);

    Http::assertSentCount(1);
});

it('stores nested venue quick-create address data when picker mode is used', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake([
        'https://maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Masjid+Jamik+Ungku+Ahmad,+Kampung+Separap/@1.9089362,102.865462,925m/data=!3m2!1e3!4b1!4m6!3m5!1s0x31d0539173ae7dd9:0xb4fce77c077ec5f3!8m2!3d1.9089362!4d102.865462!16s%2Fg%2F11sqw6yjrc?hl=en-US&entry=ttu',
        ]),
    ]);

    $venueId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Event Quick Create',
        'type' => 'dewan',
        'address' => [
            'country_id' => 132,
            'google_maps_url' => 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8',
            'google_maps_remote_lookup_enabled' => false,
        ],
    ]);

    $venue = Venue::query()
        ->with('address')
        ->findOrFail($venueId);

    expect($venue->addressModel?->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=1.9089362%2C102.865462')
        ->and($venue->addressModel?->google_place_id)->toBeNull();
    expect(abs(((float) $venue->addressModel?->lat) - 1.9089362))->toBeLessThan(0.000001);
    expect(abs(((float) $venue->addressModel?->lng) - 102.865462))->toBeLessThan(0.000001);

    Http::assertSentCount(1);
});

it('stores country-only address data in institution and venue quick-create flows', function () {
    $country = Country::query()->find(132);

    if (! $country instanceof Country) {
        $country = new Country;
        $country->forceFill([
            'id' => 132,
            'name' => 'Malaysia',
            'iso2' => 'MY',
            'iso3' => 'MYS',
            'phone_code' => '60',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
            'status' => 1,
        ]);
        $country->save();
    }

    $institutionId = InstitutionFormSchema::createOptionUsing([
        'name' => 'Masjid Country Only',
        'type' => 'masjid',
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ]);

    $venueId = VenueFormSchema::createOptionUsing([
        'name' => 'Dewan Country Only',
        'type' => 'dewan',
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ]);

    $institution = Institution::query()
        ->with('address')
        ->findOrFail($institutionId);
    $venue = Venue::query()
        ->with('address')
        ->findOrFail($venueId);

    expect($institution->addressModel?->country_id)->toBe((int) $country->getKey())
        ->and($venue->addressModel?->country_id)->toBe((int) $country->getKey());
});

it('uses rich description and no logo upload in the institution contribution form', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $components = collect($flatten(InstitutionContributionFormSchema::components(includeMedia: true)))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    expect($components->get('nickname'))->toBeInstanceOf(TextInput::class)
        ->and($components->get('description'))->toBeInstanceOf(RichEditor::class)
        ->and($components->get('contacts'))->toBeInstanceOf(Repeater::class)
        ->and($components->has('logo'))->toBeFalse();
});

it('keeps institution cover uploads locked to a 16:9 ratio across public and admin forms', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponents = $reflection->getProperty('childComponents')->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $coverFields = [
        collect($flatten(InstitutionContributionFormSchema::components(includeMedia: true)))
            ->first(fn (mixed $component): bool => method_exists($component, 'getName') && $component->getName() === 'cover'),
        collect($flatten(InstitutionFormSchema::createOptionForm()))
            ->first(fn (mixed $component): bool => method_exists($component, 'getName') && $component->getName() === 'cover'),
        collect($flatten(AdminInstitutionForm::configure(Schema::make())->getComponents()))
            ->first(fn (mixed $component): bool => method_exists($component, 'getName') && $component->getName() === 'cover'),
    ];

    foreach ($coverFields as $coverField) {
        expect($coverField)->toBeInstanceOf(SpatieMediaLibraryFileUpload::class);

        if (! $coverField instanceof SpatieMediaLibraryFileUpload) {
            continue;
        }

        expect($coverField->getImageAspectRatio())
            ->toBe('16:9');
    }
});

it('uses the standard media grid layout on the speaker contribution media form', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponents = $reflection->getProperty('childComponents')->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $components = collect($flatten(SpeakerContributionFormSchema::components(includeMedia: true)))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $avatar = $components->get('avatar');
    $cover = $components->get('cover');
    $gallery = $components->get('gallery');

    expect($avatar)->toBeInstanceOf(SpatieMediaLibraryFileUpload::class)
        ->and($cover)->toBeInstanceOf(SpatieMediaLibraryFileUpload::class)
        ->and($gallery)->toBeInstanceOf(SpatieMediaLibraryFileUpload::class);

    if (! $avatar instanceof SpatieMediaLibraryFileUpload
        || ! $cover instanceof SpatieMediaLibraryFileUpload
        || ! $gallery instanceof SpatieMediaLibraryFileUpload) {
        return;
    }

    expect($avatar->getColumnSpan('default'))->toBe(1)
        ->and($avatar->getExtraFieldWrapperAttributes())->toBe([])
        ->and($avatar->getExtraAttributes())->toBe([])
        ->and($cover->getImageAspectRatio())->toBe('4:5')
        ->and($gallery->getColumnSpan('default'))->toBe(1);
});

it('places the institution contribution location section directly after the profile section', function () {
    $sectionHeadings = collect(InstitutionContributionFormSchema::components(
        includeMedia: true,
        requireGoogleMaps: true,
        addressStatePath: 'address',
        includeLocationPicker: true,
    ))
        ->map(fn (mixed $component): ?string => $component instanceof Section ? (string) $component->getHeading() : null)
        ->filter()
        ->values()
        ->all();

    expect($sectionHeadings)->toBe([
        __('Institution Profile'),
        __('Address'),
        __('Media'),
        __('Contact'),
        __('Social Media'),
    ]);
});

it('keeps the institution contribution country field hidden while still storing its state', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $components = collect($flatten(InstitutionContributionFormSchema::components(
        includeMedia: true,
        requireGoogleMaps: true,
        addressStatePath: 'address',
        includeLocationPicker: true,
    )))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    expect($components->get('country_id'))->toBeInstanceOf(Hidden::class);
});

it('uses a reduced country-plus-region address contract across speaker create and contribution forms', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponentsProperty = $reflection->getProperty('childComponents');
            $childComponents = $childComponentsProperty->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $quickCreateComponents = collect($flatten(SpeakerFormSchema::createOptionForm()))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $contributionComponents = collect($flatten(SpeakerContributionFormSchema::components(
        includeMedia: true,
        addressStatePath: 'address',
        regionOnlyAddress: true,
        showCountryField: false,
    )))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    foreach (['bio', 'language_ids', 'institution_id', 'institution_position', 'qualifications', 'contacts', 'social_media', 'avatar', 'cover', 'gallery'] as $field) {
        expect($quickCreateComponents->has($field))->toBeTrue();
        expect($contributionComponents->has($field))->toBeTrue();
    }

    foreach (['country_id', 'state_id', 'district_id', 'subdistrict_id'] as $field) {
        expect($quickCreateComponents->has($field))->toBeTrue();
        expect($contributionComponents->has($field))->toBeTrue();
    }

    foreach (['line1', 'line2', 'postcode', 'google_maps_url', 'waze_url', 'lat', 'lng'] as $field) {
        expect($quickCreateComponents->has($field))->toBeFalse();
        expect($contributionComponents->has($field))->toBeFalse();
    }
});

it('stores structured speaker quick-create details when creating a speaker via quick-create', function () {
    $language = Language::where('code', 'ms')->first() ?? Language::query()->create([
        'code' => 'ms',
        'name' => 'Malay',
        'name_native' => 'Bahasa Melayu',
        'dir' => 'ltr',
    ]);

    $speakerId = SpeakerFormSchema::createOptionUsing([
        'name' => 'Ustaz Quick Create',
        'gender' => 'male',
        'country_id' => 132,
        'bio' => ['type' => 'doc', 'content' => []],
        'qualifications' => [[
            'institution' => 'Universiti Islam',
            'degree' => 'MA',
            'field' => 'Dakwah',
            'year' => '2020',
        ]],
        'language_ids' => [$language->id],
        'state_id' => 1,
        'contacts' => [[
            'category' => 'phone',
            'value' => '0123456789',
            'type' => 'main',
            'is_public' => true,
        ]],
        'social_media' => [[
            'platform' => 'facebook',
            'url' => 'https://facebook.com/ustaz.quick.create',
        ], [
            'platform' => 'youtube',
            'url' => 'https://youtube.com/@ustaz.quick.create',
        ]],
    ]);

    $speaker = Speaker::query()
        ->with(['address', 'contacts', 'socialMedia', 'languages'])
        ->findOrFail($speakerId);

    expect($speaker->qualifications)->toBeArray()
        ->and($speaker->addressModel?->country_id)->toBe(132)
        ->and($speaker->addressModel?->state_id)->toBe(1)
        ->and($speaker->addressModel?->line1)->toBeNull()
        ->and($speaker->addressModel?->google_maps_url)->toBeNull()
        ->and($speaker->contacts->pluck('value')->all())->toContain('0123456789')
        ->and($speaker->socialMedia->pluck('platform')->all())->toEqual(['facebook', 'youtube'])
        ->and($speaker->socialMedia->pluck('order_column')->all())->toEqual([1, 2])
        ->and($speaker->languages->pluck('id')->all())->toContain($language->id);
});

it('hides country fields in speaker public creation forms', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponents = $reflection->getProperty('childComponents')->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $quickCreateCountry = collect($flatten(SpeakerFormSchema::createOptionForm()))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('country_id');
    $contributionCountry = collect($flatten(SpeakerContributionFormSchema::components(
        includeMedia: true,
        addressStatePath: 'address',
        regionOnlyAddress: true,
        showCountryField: false,
    )))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('country_id');

    expect($quickCreateCountry)->toBeInstanceOf(Hidden::class)
        ->and($contributionCountry)->toBeInstanceOf(Hidden::class);
});

it('hides country fields in the admin speaker form', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponents = $reflection->getProperty('childComponents')->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $adminComponents = collect($flatten(AdminSpeakerForm::configure(Schema::make())->getComponents()))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);
    $country = $adminComponents->get('country_id');

    expect($country)->toBeInstanceOf(Hidden::class);

    foreach (['state_id', 'district_id', 'subdistrict_id'] as $field) {
        expect($adminComponents->has($field))->toBeTrue();
    }

    foreach (['line1', 'line2', 'postcode', 'google_maps_url', 'waze_url', 'lat', 'lng'] as $field) {
        expect($adminComponents->has($field))->toBeFalse();
    }
});

it('requires country fields in the admin institution and venue forms', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
            $flattened[] = $component;

            $reflection = new ReflectionObject($component);

            while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
                $reflection = $parent;
            }

            if (! $reflection->hasProperty('childComponents')) {
                continue;
            }

            $childComponents = $reflection->getProperty('childComponents')->getValue($component);

            if (! is_array($childComponents)) {
                continue;
            }

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $institutionCountry = collect($flatten(AdminInstitutionForm::configure(Schema::make())->getComponents()))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('country_id');
    $venueCountry = collect($flatten(AdminVenueForm::configure(Schema::make())->getComponents()))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->get('country_id');

    expect($institutionCountry)->toBeInstanceOf(Select::class)
        ->and($venueCountry)->toBeInstanceOf(Select::class);
    expect(method_exists($institutionCountry, 'isRequired'))->toBeTrue();
    expect(method_exists($venueCountry, 'isRequired'))->toBeTrue();
    expect($institutionCountry?->isRequired())->toBeTrue();
    expect($venueCountry?->isRequired())->toBeTrue();
});
