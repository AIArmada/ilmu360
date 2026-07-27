<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\State;
use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Filament\Resources\Institutions\Pages\CreateInstitution;
use App\Forms\InstitutionFormSchema;
use App\Jobs\BackfillInstitutionSlugs;
use App\Models\Institution;
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
        ->and($institution->fresh()?->primaryAddress()?->country_id)->toBe((string) $geography['country']->getKey());
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

    attachInstitutionSlugAddress($first, $geography);
    attachInstitutionSlugAddress($second, $geography);

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

    $institution->primaryAddress()?->update([
        'country_id' => (string) $secondaryGeography['country']->getKey(),
        'state_id' => (string) $secondaryGeography['state']->getKey(),
        'administrative_district_id' => (string) $secondaryGeography['district']->getKey(),
        'administrative_subdivision_id' => (string) $secondaryGeography['subdistrict']->getKey(),
        'state' => (string) $secondaryGeography['state']->name,
        'city' => (string) $secondaryGeography['subdistrict']->name,
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
            'contactMethods' => [],
            'socialProfiles' => [],
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
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam');

    $generator = app(GenerateInstitutionSlugAction::class);

    expect($generator->handle('Masjid Tanpa Mukim', [
        'country_id' => (string) $geo['country']->getKey(),
    ]))->toBe('masjid-tanpa-mukim-my')
        ->and($generator->handle('Masjid Tanpa Daerah', [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
        ]))->toBe('masjid-tanpa-daerah-selangor-my')
        ->and($generator->handle('Masjid Lengkap Sebahagian', [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
            'administrative_district_id' => (string) $geo['district']->getKey(),
        ]))->toBe('masjid-lengkap-sebahagian-petaling-selangor-my')
        ->and($generator->handle('Masjid Tanpa Lokasi'))->toBe('masjid-tanpa-lokasi');
});

/**
 * @return array{
 *     country: AddressCountry,
 *     state: State,
 *     district: AddressArea,
 *     subdistrict: AddressArea,
 *     area_tree_root: AddressArea
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
    $country = ensureTestAddressCountry(
        iso2: $countryIso2,
        name: $countryName,
        iso3: $countryIso3,
        phoneCode: '60',
    );

    $geo = createTestPackageGeography($stateName, $districtName, $subdistrictName, country: $country);

    return [
        'country' => $geo['country'],
        'state' => $geo['state'],
        'district' => $geo['district'],
        'subdistrict' => $geo['subdistrict'],
        'area_tree_root' => $geo['area_tree_root'],
    ];
}

/**
 * @param  array{
 *     country: AddressCountry,
 *     state: State,
 *     district: AddressArea,
 *     subdistrict: AddressArea
 * }  $geography
 * @return array<string, string|null>
 */
function geographyAddressPayload(array $geography): array
{
    return [
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'administrative_district_id' => (string) $geography['district']->getKey(),
        'administrative_subdivision_id' => (string) $geography['subdistrict']->getKey(),
        'line1' => 'Persiaran Masjid',
        'google_maps_url' => 'https://maps.google.com/?q=3.0738,101.5183',
    ];
}

/**
 * @param  array{
 *     country: AddressCountry,
 *     state: State,
 *     district: AddressArea,
 *     subdistrict: AddressArea
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
    ]));

    attachInstitutionSlugAddress($institution, $geography);

    return $institution->fresh(['addresses']) ?? $institution;
}

/**
 * @param  array{
 *     country: AddressCountry,
 *     state: State,
 *     district: AddressArea,
 *     subdistrict: AddressArea
 * }  $geography
 */
function attachInstitutionSlugAddress(Institution $institution, array $geography): void
{
    $address = Address::query()->create([
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'administrative_district_id' => (string) $geography['district']->getKey(),
        'administrative_subdivision_id' => (string) $geography['subdistrict']->getKey(),
        'state' => (string) $geography['state']->name,
        'city' => (string) $geography['subdistrict']->name,
        'line1' => 'Persiaran Masjid',
        'google_maps_url' => 'https://maps.google.com/?q=3.0738,101.5183',
    ]);

    $institution->attachAddress($address, type: 'primary', isPrimary: true);
}
