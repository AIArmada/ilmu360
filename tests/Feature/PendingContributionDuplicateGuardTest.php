<?php

use App\Actions\Contributions\Submission\EnsureUniqueContributionCreateAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Forms\SharedFormSchema;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\User;
use App\Shared\Directory\Enums\InstitutionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

function pendingDupGuardErrors(callable $submit): array
{
    try {
        $submit();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    Assert::fail('Expected the duplicate guard to reject the submission.');
}

function pendingDupGuardGeography(): array
{
    $geography = createTestPackageGeography(cityName: 'Shah Alam');

    return [
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'city_id' => (string) $geography['city']->getKey(),
    ];
}

function pendingDupGuardClaim(array $proposedData, array $overrides = []): ContributionRequest
{
    return ContributionRequest::factory()->create(array_replace([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => null,
        'proposer_id' => User::factory(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => $proposedData,
    ], $overrides));
}

it('blocks a create when a pending create request claims the same Google place', function () {
    $locality = pendingDupGuardGeography();

    pendingDupGuardClaim([
        'name' => 'Unrelated Pending Name',
        'address' => $locality + ['google_place_id' => 'claimed_place_id'],
    ]);

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Anything Else',
                'address' => $locality + ['google_place_id' => 'claimed_place_id'],
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));
});

it('blocks a create when a pending update request claims the same Google cid', function () {
    $locality = pendingDupGuardGeography();
    $target = Institution::factory()->create(['status' => InstitutionStatus::Verified]);

    pendingDupGuardClaim(
        ['address' => $locality + ['google_cid' => '11885663895765773631']],
        [
            'type' => ContributionRequestType::Update,
            'entity_id' => (string) $target->getKey(),
        ],
    );

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Brand New Institution',
                'address' => $locality + ['google_maps_url' => 'https://maps.google.com/?cid=11885663895765773631'],
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));
});

it('blocks a create on matching name and locality from a pending create', function () {
    $locality = pendingDupGuardGeography();

    pendingDupGuardClaim([
        'name' => 'Masjid Pending Review',
        'address' => $locality,
    ]);

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Masjid  Pending Review',
                'address' => $locality,
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));
});

it('ignores decided and non-institution pending requests', function () {
    $locality = pendingDupGuardGeography();

    foreach ([ContributionRequestStatus::Approved, ContributionRequestStatus::Rejected, ContributionRequestStatus::Cancelled] as $status) {
        pendingDupGuardClaim(
            [
                'name' => 'Masjid Decided',
                'address' => $locality + ['google_place_id' => 'decided_place_id'],
            ],
            ['status' => $status],
        );
    }

    pendingDupGuardClaim(
        [
            'name' => 'Masjid Decided',
            'address' => $locality + ['google_place_id' => 'decided_place_id'],
        ],
        ['subject_type' => ContributionSubjectType::Person],
    );

    $submit = function () use ($locality): void {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Masjid Decided',
                'address' => $locality + ['google_place_id' => 'decided_place_id'],
            ],
        );
    };

    expect($submit)->not->toThrow(ValidationException::class);
});

it('lets an update keep the identity its own pending request claims', function () {
    $locality = pendingDupGuardGeography();
    $target = Institution::factory()->create(['status' => InstitutionStatus::Verified]);

    pendingDupGuardClaim(
        ['address' => $locality + ['google_place_id' => 'own_pending_place']],
        [
            'type' => ContributionRequestType::Update,
            'entity_id' => (string) $target->getKey(),
        ],
    );

    $taken = app(EnsureUniqueContributionCreateAction::class)->institutionMapsIdentityTakenByOther(
        null,
        'own_pending_place',
        (string) $target->getKey(),
    );

    expect($taken)->toBeFalse();
});

it('blocks an update moving onto another pending update claim', function () {
    $locality = pendingDupGuardGeography();
    $other = Institution::factory()->create(['status' => InstitutionStatus::Verified]);
    $moving = Institution::factory()->create(['status' => InstitutionStatus::Verified]);

    pendingDupGuardClaim(
        ['address' => $locality + ['google_place_id' => 'claimed_by_other_update']],
        [
            'type' => ContributionRequestType::Update,
            'entity_id' => (string) $other->getKey(),
        ],
    );

    $taken = app(EnsureUniqueContributionCreateAction::class)->institutionMapsIdentityTakenByOther(
        null,
        'claimed_by_other_update',
        (string) $moving->getKey(),
    );

    expect($taken)->toBeTrue();
});

it('matches name-only pending updates against the target locality', function () {
    $locality = pendingDupGuardGeography();
    $target = Institution::factory()->create(['status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($target, $locality, allowCountryOnly: true);

    pendingDupGuardClaim(
        ['name' => 'Renamed Pending Institution'],
        [
            'type' => ContributionRequestType::Update,
            'entity_id' => (string) $target->getKey(),
        ],
    );

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Renamed Pending Institution',
                'address' => $locality,
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));

    $otherCountry = ensureTestAddressCountry(iso2: 'SG', name: 'Singapore');

    $otherSubmit = function () use ($otherCountry): void {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Renamed Pending Institution',
                'address' => ['country_id' => (string) $otherCountry->getKey()],
            ],
        );
    };

    expect($otherSubmit)->not->toThrow(ValidationException::class);
});

it('prefers the existing-record message when both an institution and a pending claim match', function () {
    $locality = pendingDupGuardGeography();
    $existing = Institution::factory()->create(['status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($existing, $locality + ['google_place_id' => 'shared_place_id'], allowCountryOnly: true);

    pendingDupGuardClaim([
        'name' => 'Something Else',
        'address' => $locality + ['google_place_id' => 'shared_place_id'],
    ]);

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Another Name',
                'address' => $locality + ['google_place_id' => 'shared_place_id'],
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('An institution with the same Google Maps place or name and locality already exists. Please submit an update instead of creating a new record.'));
});

it('matches pending claims when UUID casing differs', function () {
    $locality = pendingDupGuardGeography();
    $upperLocality = array_map(
        static fn (mixed $value): mixed => is_string($value) ? mb_strtoupper($value) : $value,
        $locality,
    );

    pendingDupGuardClaim([
        'name' => 'Masjid Case Insensitive',
        'address' => $upperLocality,
    ]);

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Masjid Case Insensitive',
                'address' => $locality,
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));
});

it('matches a pending claim whose canonical cid is not the first URL value', function () {
    $locality = pendingDupGuardGeography();

    pendingDupGuardClaim([
        'name' => 'Unrelated Second Cid Name',
        'address' => $locality + ['google_maps_url' => 'https://maps.google.com/?cid=111&ludocid=222'],
    ]);

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Anything Else',
                'address' => $locality + ['google_cid' => '222'],
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));
});

it('matches a pending feature-id claim against an incoming cid', function () {
    $locality = pendingDupGuardGeography();

    pendingDupGuardClaim([
        'name' => 'Unrelated Feature Name',
        'address' => $locality + ['google_feature_id' => '0x0:0x75bcd15'],
    ]);

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Anything Else',
                'address' => $locality + ['google_cid' => '123456789'],
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));
});

it('still blocks on a Maps claim whose stored geography no longer exists', function () {
    $locality = pendingDupGuardGeography();
    $deletedCityId = (string) Str::uuid();
    $deletedAreaId = (string) Str::uuid();

    pendingDupGuardClaim([
        'name' => 'Unrelated Stale Geo Name',
        'address' => $locality + [
            'city_id' => $deletedCityId,
            'area_assignments' => ['administrative_district' => $deletedAreaId],
            'google_place_id' => 'stale_geo_place',
        ],
    ]);

    $errors = pendingDupGuardErrors(function () use ($locality): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Anything Else',
                'address' => $locality + ['google_place_id' => 'stale_geo_place'],
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));
});

it('matches area assignments regardless of UUID casing', function () {
    $locality = pendingDupGuardGeography();
    $geography = createTestPackageGeography(cityName: 'Shah Alam');
    $districtId = (string) $geography['district']->getKey();

    pendingDupGuardClaim([
        'name' => 'Masjid Case District',
        'address' => $locality + ['area_assignments' => ['administrative_district' => mb_strtoupper($districtId)]],
    ]);

    $errors = pendingDupGuardErrors(function () use ($locality, $districtId): array {
        app(EnsureUniqueContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Masjid Case District',
                'address' => $locality + ['area_assignments' => ['administrative_district' => $districtId]],
            ],
        );

        return [];
    });

    expect($errors['name'][0] ?? null)->toBe(__('This institution was already submitted and is awaiting review. Please wait for the review instead of creating a new record.'));
});
