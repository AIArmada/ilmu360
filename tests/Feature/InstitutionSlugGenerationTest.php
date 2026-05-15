<?php

use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Filament\Resources\Institutions\Pages\CreateInstitution;
use App\Forms\InstitutionFormSchema;
use App\Jobs\BackfillInstitutionSlugs;
use App\Models\Country;
use App\Models\District;
use App\Models\Institution;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Support\Cache\PublicListingsCache;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('generates geographic slugs for institution quick-create flows', function () {
    $geography = createInstitutionSlugGeography();

    $institutionId = InstitutionFormSchema::createOptionUsing([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ]);

    $institution = Institution::query()->findOrFail($institutionId);

    expect($institution->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-shah-alam-petaling-selangor-my');
});

it('persists country-only address data for staged institution creation', function () {
    $proposer = User::factory()->create();
    $geography = createInstitutionSlugGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Negara Sahaja',
        'type' => 'masjid',
        'address' => [
            'country_id' => (string) $geography['country']->getKey(),
        ],
    ], $proposer);

    expect($institution->slug)->toBe('masjid-negara-sahaja-my')
        ->and($institution->fresh()?->addressModel?->country_id)->toBe((int) $geography['country']->getKey());
});

it('adds duplicate numbering only when the same institution name reuses the same locality suffix', function () {
    $proposer = User::factory()->create();
    $primaryGeography = createInstitutionSlugGeography();
    $secondaryGeography = createInstitutionSlugGeography(
        subdistrictName: 'Subang Jaya',
    );

    $first = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'type' => 'masjid',
        'address' => geographyAddressPayload($primaryGeography),
    ], $proposer);

    $second = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'type' => 'masjid',
        'address' => geographyAddressPayload($primaryGeography),
    ], $proposer);

    $third = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'type' => 'masjid',
        'address' => geographyAddressPayload($secondaryGeography),
    ], $proposer);

    expect($first->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-shah-alam-petaling-selangor-my')
        ->and($second->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-2-shah-alam-petaling-selangor-my')
        ->and($third->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-subang-jaya-petaling-selangor-my');
});

it('recomputes duplicate institution slugs when locality is added after creation', function () {
    $proposer = User::factory()->create();
    $geography = createInstitutionSlugGeography();

    $first = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'type' => 'masjid',
    ], $proposer);

    $second = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'type' => 'masjid',
    ], $proposer);

    expect($first->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah')
        ->and($second->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-2');

    $first->address()->create([
        'type' => 'main',
        'country_id' => (int) $geography['country']->getKey(),
        'state_id' => (int) $geography['state']->getKey(),
        'district_id' => (int) $geography['district']->getKey(),
        'subdistrict_id' => (int) $geography['subdistrict']->getKey(),
        'line1' => 'Persiaran Masjid',
        'google_maps_url' => 'https://maps.google.com/?q=3.0738,101.5183',
    ]);

    $second->address()->create([
        'type' => 'main',
        'country_id' => (int) $geography['country']->getKey(),
        'state_id' => (int) $geography['state']->getKey(),
        'district_id' => (int) $geography['district']->getKey(),
        'subdistrict_id' => (int) $geography['subdistrict']->getKey(),
        'line1' => 'Persiaran Masjid',
        'google_maps_url' => 'https://maps.google.com/?q=3.0738,101.5183',
    ]);

    expect($first->fresh()?->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-shah-alam-petaling-selangor-my')
        ->and($second->fresh()?->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-2-shah-alam-petaling-selangor-my');
});

it('keeps slugs unique when a literal numbered name already occupies the expected duplicate slot', function () {
    $proposer = User::factory()->create();
    $geography = createInstitutionSlugGeography();

    $first = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Example',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ], $proposer);

    $numberedName = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Example 2',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ], $proposer);

    $duplicate = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Example',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ], $proposer);

    expect($first->slug)->toBe('masjid-example-shah-alam-petaling-selangor-my')
        ->and($numberedName->slug)->toBe('masjid-example-2-shah-alam-petaling-selangor-my')
        ->and($duplicate->slug)->toBe('masjid-example-3-shah-alam-petaling-selangor-my');
});

it('recomputes institution slugs when the institution name changes', function () {
    $proposer = User::factory()->create();
    $geography = createInstitutionSlugGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Lama',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ], $proposer);

    $institution->update([
        'name' => 'Masjid Baru',
    ]);

    expect($institution->fresh()?->slug)->toBe('masjid-baru-shah-alam-petaling-selangor-my');
});

it('recomputes institution slugs when the institution locality changes', function () {
    $proposer = User::factory()->create();
    $primaryGeography = createInstitutionSlugGeography();
    $secondaryGeography = createInstitutionSlugGeography(subdistrictName: 'Subang Jaya');

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Lama',
        'type' => 'masjid',
        'address' => geographyAddressPayload($primaryGeography),
    ], $proposer);

    $institution->addressModel?->update([
        'subdistrict_id' => (int) $secondaryGeography['subdistrict']->getKey(),
        'district_id' => (int) $secondaryGeography['district']->getKey(),
        'state_id' => (int) $secondaryGeography['state']->getKey(),
        'country_id' => (int) $secondaryGeography['country']->getKey(),
    ]);

    expect($institution->fresh()?->slug)->toBe('masjid-lama-subang-jaya-petaling-selangor-my');
});

it('renumbers remaining duplicates when a peer is renamed out of the group', function () {
    $proposer = User::factory()->create();
    $geography = createInstitutionSlugGeography();

    $first = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ], $proposer);

    $second = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ], $proposer);

    expect($first->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-shah-alam-petaling-selangor-my')
        ->and($second->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-2-shah-alam-petaling-selangor-my');

    $first->update([
        'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah Induk',
    ]);

    expect($first->fresh()?->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-induk-shah-alam-petaling-selangor-my')
        ->and($second->fresh()?->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-shah-alam-petaling-selangor-my');
});

it('renumbers remaining duplicates when a peer is deleted', function () {
    $proposer = User::factory()->create();
    $geography = createInstitutionSlugGeography();

    $first = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Warisan',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ], $proposer);

    $second = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Warisan',
        'type' => 'masjid',
        'address' => geographyAddressPayload($geography),
    ], $proposer);

    expect($second->slug)->toBe('masjid-warisan-2-shah-alam-petaling-selangor-my');

    $first->delete();

    expect($second->fresh()?->slug)->toBe('masjid-warisan-shah-alam-petaling-selangor-my');
});

it('uses the generated geographic slug when admins create institutions in filament', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $geography = createInstitutionSlugGeography();

    Livewire::actingAs($administrator)
        ->test(CreateInstitution::class)
        ->fillForm([
            'type' => 'masjid',
            'name' => 'Masjid Sultan Salahudin Abdul Aziz Shah',
            'slug' => 'temporary-admin-slug',
            'status' => 'verified',
            'is_active' => true,
            'contacts' => [],
            'socialMedia' => [],
            'address' => geographyAddressPayload($geography),
        ])
        ->call('create')
        ->assertHasNoErrors();

    $institution = Institution::query()
        ->where('name', 'Masjid Sultan Salahudin Abdul Aziz Shah')
        ->firstOrFail();

    expect($institution->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-shah-alam-petaling-selangor-my');
});

it('backfills existing institution slugs through the queued job logic', function () {
    $geography = createInstitutionSlugGeography();

    $first = createInstitutionForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000001',
        name: 'Masjid Sultan Salahudin Abdul Aziz Shah',
        slug: 'legacy-random-1',
        geography: $geography,
    );

    $second = createInstitutionForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000002',
        name: 'Masjid Sultan Salahudin Abdul Aziz Shah',
        slug: 'legacy-random-2',
        geography: $geography,
    );

    app(BackfillInstitutionSlugs::class)->handle(
        app(GenerateInstitutionSlugAction::class),
        app(PublicListingsCache::class),
    );

    expect($first->fresh()?->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-shah-alam-petaling-selangor-my')
        ->and($second->fresh()?->slug)->toBe('masjid-sultan-salahudin-abdul-aziz-shah-2-shah-alam-petaling-selangor-my');
});

it('queues the institution slug backfill command', function () {
    Queue::fake();

    $this->artisan('institutions:queue-slug-backfill')
        ->expectsOutput('Queued institution slug backfill job.')
        ->assertSuccessful();

    Queue::assertPushed(BackfillInstitutionSlugs::class);
});

it('skips null locality segments when generating institution slugs', function () {
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

    $generator = app(GenerateInstitutionSlugAction::class);

    expect($generator->handle('Masjid Tanpa Mukim', [
        'country_id' => '132',
    ]))->toBe('masjid-tanpa-mukim-my')
        ->and($generator->handle('Masjid Tanpa Daerah', [
            'country_id' => '132',
            'state_name' => 'Selangor',
        ]))->toBe('masjid-tanpa-daerah-selangor-my')
        ->and($generator->handle('Masjid Lengkap Sebahagian', [
            'country_id' => '132',
            'district_name' => 'Petaling',
            'state_name' => 'Selangor',
        ]))->toBe('masjid-lengkap-sebahagian-petaling-selangor-my')
        ->and($generator->handle('Masjid Tanpa Lokasi'))->toBe('masjid-tanpa-lokasi');
});

/**
 * @return array{
 *     country: Country,
 *     state: State,
 *     district: District,
 *     subdistrict: Subdistrict
 * }
 */
function createInstitutionSlugGeography(
    string $countryName = 'Malaysia',
    string $countryIso2 = 'MY',
    string $countryIso3 = 'MYS',
    int $countryId = 132,
    string $stateName = 'Selangor',
    string $districtName = 'Petaling',
    string $subdistrictName = 'Shah Alam',
): array {
    $country = Country::query()->find($countryId);

    if (! $country instanceof Country) {
        $country = new Country;
        $country->forceFill([
            'id' => $countryId,
            'name' => $countryName,
            'iso2' => $countryIso2,
            'iso3' => $countryIso3,
            'phone_code' => '60',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
            'status' => 1,
        ]);
        $country->save();
    }

    $state = State::query()->create([
        'country_id' => (int) $country->getKey(),
        'name' => $stateName,
        'country_code' => $countryIso2,
    ]);

    $district = District::query()->create([
        'country_id' => (int) $country->getKey(),
        'state_id' => (int) $state->getKey(),
        'country_code' => $countryIso2,
        'name' => $districtName,
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $country->getKey(),
        'state_id' => (int) $state->getKey(),
        'district_id' => (int) $district->getKey(),
        'country_code' => $countryIso2,
        'name' => $subdistrictName,
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
 *     country: Country,
 *     state: State,
 *     district: District,
 *     subdistrict: Subdistrict
 * }  $geography
 * @return array<string, string>
 */
function geographyAddressPayload(array $geography): array
{
    return [
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'district_id' => (string) $geography['district']->getKey(),
        'subdistrict_id' => (string) $geography['subdistrict']->getKey(),
        'line1' => 'Persiaran Masjid',
        'google_maps_url' => 'https://maps.google.com/?q=3.0738,101.5183',
    ];
}

/**
 * @param  array{
 *     country: Country,
 *     state: State,
 *     district: District,
 *     subdistrict: Subdistrict
 * }  $geography
 */
function createInstitutionForSlugBackfill(string $id, string $name, string $slug, array $geography): Institution
{
    $institution = Institution::unguarded(fn () => Institution::query()->create([
        'id' => $id,
        'type' => 'masjid',
        'name' => $name,
        'slug' => $slug,
        'status' => 'verified',
        'is_active' => true,
    ]));

    $institution->address()->create([
        'type' => 'main',
        'country_id' => (int) $geography['country']->getKey(),
        'state_id' => (int) $geography['state']->getKey(),
        'district_id' => (int) $geography['district']->getKey(),
        'subdistrict_id' => (int) $geography['subdistrict']->getKey(),
        'line1' => 'Persiaran Masjid',
        'google_maps_url' => 'https://maps.google.com/?q=3.0738,101.5183',
    ]);

    return $institution->fresh(['address']) ?? $institution;
}
