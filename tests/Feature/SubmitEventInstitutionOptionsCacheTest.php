<?php

use App\Features\Directory\Api\InstitutionSubmissionAccess;
use App\Models\Institution;
use App\Models\User;
use App\Shared\Submission\SubmissionOptionsCache;
use App\Support\Submission\SubmitEventOptionsProvider;
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
    $optionsCache = app(SubmissionOptionsCache::class);

    expect(app(SubmitEventOptionsProvider::class)->institutionOptions(null, $malaysiaId, null))
        ->toHaveKey((string) $malaysiaInstitution->getKey())
        ->and(app(SubmitEventOptionsProvider::class)->institutionOptions(null, $singaporeId, null))
        ->toHaveKey((string) $singaporeInstitution->getKey());

    $malaysiaKey = $optionsCache->institutionOptionsKey($malaysiaId);
    $singaporeKey = $optionsCache->institutionOptionsKey($singaporeId);

    expect(Cache::has($malaysiaKey))->toBeTrue()
        ->and(Cache::has($singaporeKey))->toBeTrue();

    $admin = b1SubmissionAdmin();
    app(InstitutionSubmissionAccess::class)->lock((string) $malaysiaInstitution->getKey(), (string) $admin->getKey());

    expect($optionsCache->institutionOptionsKey($malaysiaId))->not->toBe($malaysiaKey)
        ->and($optionsCache->institutionOptionsKey($singaporeId))->not->toBe($singaporeKey);
});
