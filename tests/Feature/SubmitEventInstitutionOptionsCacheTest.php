<?php

use App\Features\Institutions\Api\InstitutionSubmissionAccess;
use App\Features\Institutions\Api\InstitutionSubmissionOptionsCache;
use App\Features\Persons\Api\PersonSubmissionAccess;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Models\User;
use App\Features\Events\Services\SubmitEventOptionsProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    Cache::flush();
});

function b1EligibleInstitution(string $countryId): Institution
{
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($institution, ['country_id' => $countryId]);

    $owner = User::factory()->create([
        'phone' => fake()->unique()->numerify('+601########'),
        'phone_verified_at' => Carbon::now(),
    ]);

    $institution->members()->syncWithoutDetaching([$owner->getKey()]);
    $institution->members()->updateExistingPivot($owner->getKey(), ['role' => 'owner']);

    return $institution->refresh();
}

function b1EligiblePerson(): Person
{
    $person = Person::factory()->create([
        'allow_public_event_submission' => true,
        'status' => 'verified',
    ]);

    $owner = User::factory()->create([
        'phone' => fake()->unique()->numerify('+601########'),
        'phone_verified_at' => Carbon::now(),
    ]);

    $person->members()->syncWithoutDetaching([$owner->getKey()]);
    $person->members()->updateExistingPivot($owner->getKey(), ['role' => 'owner']);

    return $person->refresh();
}

function b1SubmissionAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

it('refreshes cached guest institution options when submission access is locked and unlocked', function (): void {
    $countryId = testMalaysiaCountryId();
    $institution = b1EligibleInstitution($countryId);
    $institutionId = (string) $institution->getKey();

    expect(app(SubmitEventOptionsProvider::class)->institutionOptions(null, $countryId, null))
        ->toHaveKey($institutionId);

    $admin = b1SubmissionAdmin();

    app(InstitutionSubmissionAccess::class)->lock($institutionId, (string) $admin->getKey());

    expect(app(SubmitEventOptionsProvider::class)->institutionOptions(null, $countryId, null))
        ->not->toHaveKey($institutionId);

    app(InstitutionSubmissionAccess::class)->unlock($institutionId, (string) $admin->getKey());

    expect(app(SubmitEventOptionsProvider::class)->institutionOptions(null, $countryId, null))
        ->toHaveKey($institutionId);
});

it('invalidates cached guest institution options for every country on a version bump', function (): void {
    $malaysiaId = testMalaysiaCountryId();
    $singaporeId = (string) ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65')->getKey();

    $malaysiaInstitution = b1EligibleInstitution($malaysiaId);
    $singaporeInstitution = b1EligibleInstitution($singaporeId);
    $optionsCache = app(InstitutionSubmissionOptionsCache::class);

    $malaysiaLoads = 0;
    $singaporeLoads = 0;
    $loadMalaysia = function () use (&$malaysiaLoads, $malaysiaInstitution): array {
        $malaysiaLoads++;

        return [(string) $malaysiaInstitution->getKey() => $malaysiaInstitution->name];
    };
    $loadSingapore = function () use (&$singaporeLoads, $singaporeInstitution): array {
        $singaporeLoads++;

        return [(string) $singaporeInstitution->getKey() => $singaporeInstitution->name];
    };

    expect($optionsCache->rememberGuestOptions($malaysiaId, $loadMalaysia))->toHaveKey((string) $malaysiaInstitution->getKey())
        ->and($optionsCache->rememberGuestOptions($singaporeId, $loadSingapore))->toHaveKey((string) $singaporeInstitution->getKey());

    $optionsCache->rememberGuestOptions($malaysiaId, $loadMalaysia);
    $optionsCache->rememberGuestOptions($singaporeId, $loadSingapore);

    $admin = b1SubmissionAdmin();
    app(InstitutionSubmissionAccess::class)->lock((string) $malaysiaInstitution->getKey(), (string) $admin->getKey());

    $optionsCache->rememberGuestOptions($malaysiaId, $loadMalaysia);
    $optionsCache->rememberGuestOptions($singaporeId, $loadSingapore);

    expect($malaysiaLoads)->toBe(2)
        ->and($singaporeLoads)->toBe(2);
});

it('refreshes cached guest person options when submission access is locked and unlocked', function (): void {
    $person = b1EligiblePerson();
    $personId = (string) $person->getKey();

    expect(app(SubmitEventOptionsProvider::class)->personOptions(null))
        ->toHaveKey($personId);

    $admin = b1SubmissionAdmin();

    app(PersonSubmissionAccess::class)->lock($personId, (string) $admin->getKey());

    expect(app(SubmitEventOptionsProvider::class)->personOptions(null))
        ->not->toHaveKey($personId);

    app(PersonSubmissionAccess::class)->unlock($personId, (string) $admin->getKey());

    expect(app(SubmitEventOptionsProvider::class)->personOptions(null))
        ->toHaveKey($personId);
});
