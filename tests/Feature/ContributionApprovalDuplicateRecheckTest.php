<?php

use AIArmada\Authz\Models\Role;
use App\Actions\Contributions\Review\ApproveContributionRequestAction;
use App\Actions\Contributions\Review\RejectContributionRequestAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Exceptions\ContributionRequestAlreadyReviewedException;
use App\Exceptions\ContributionRequestNotPendingException;
use App\Forms\SharedFormSchema;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\User;
use App\Shared\Directory\Enums\InstitutionStatus;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $address
 */
function approvalRecheckStagedCreate(string $name, array $address): ContributionRequest
{
    $institution = Institution::factory()->create([
        'name' => $name,
        'status' => InstitutionStatus::Pending,
    ]);
    SharedFormSchema::createAddressFromData($institution, $address, allowCountryOnly: true);

    return ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $institution->getKey(),
        'proposer_id' => User::factory(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => $name, 'address' => $address],
    ]);
}

function approvalRecheckLocality(): array
{
    $geography = createTestPackageGeography(cityName: 'Shah Alam');

    return [
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'city_id' => (string) $geography['city']->getKey(),
    ];
}

function approvalRecheckConflictMessage(): string
{
    return __('Cannot approve this request because another institution or pending submission already claims the same Google Maps place or name and locality. Reject the conflicting submission first.');
}

it('blocks approving a create when another staged institution claims the same place', function () {
    $locality = approvalRecheckLocality();
    $first = approvalRecheckStagedCreate('First Mosque', $locality + ['google_place_id' => 'contested_place']);
    approvalRecheckStagedCreate('Second Mosque', $locality + ['google_place_id' => 'contested_place']);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($first, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    expect($first->fresh()?->status)->toBe(ContributionRequestStatus::Pending)
        ->and(Institution::query()->where('status', InstitutionStatus::Pending->value)->count())->toBe(2);
});

it('blocks approving a create when another pending update claims the same place', function () {
    $locality = approvalRecheckLocality();
    $target = Institution::factory()->create(['status' => InstitutionStatus::Verified]);

    ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $target->getKey(),
        'proposer_id' => User::factory(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['address' => $locality + ['google_place_id' => 'update_claimed_place']],
    ]);

    $create = approvalRecheckStagedCreate('Claimed Place Mosque', $locality + ['google_place_id' => 'update_claimed_place']);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($create, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    expect($create->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('approves a clean create without self-blocking on its staged institution', function () {
    $locality = approvalRecheckLocality();
    $create = approvalRecheckStagedCreate('Clean Mosque', $locality + ['google_place_id' => 'clean_place']);

    $approved = app(ApproveContributionRequestAction::class)->handle($create, User::factory()->create());

    expect($approved->status)->toBe(ContributionRequestStatus::Approved)
        ->and($approved->entity)->toBeInstanceOf(Institution::class)
        ->and($approved->entity->status)->toBe(InstitutionStatus::Verified);
});

it('blocks approving an update that moves onto a taken place', function () {
    $locality = approvalRecheckLocality();
    $holder = Institution::factory()->create(['status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($holder, $locality + ['google_place_id' => 'taken_place'], allowCountryOnly: true);
    $moving = Institution::factory()->create(['status' => InstitutionStatus::Verified]);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $moving->getKey(),
        'proposer_id' => User::factory(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['address' => $locality + ['google_place_id' => 'taken_place']],
    ]);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($update, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    expect($update->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('approves an update that keeps its own place without self-blocking', function () {
    $locality = approvalRecheckLocality();
    $entity = Institution::factory()->create(['status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($entity, $locality + ['google_place_id' => 'own_place'], allowCountryOnly: true);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $entity->getKey(),
        'proposer_id' => User::factory(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['address' => $locality + ['google_place_id' => 'own_place', 'line1' => 'Corrected line 1']],
    ]);

    $approved = app(ApproveContributionRequestAction::class)->handle($update, User::factory()->create());

    expect($approved->status)->toBe(ContributionRequestStatus::Approved);
});

it('serializes institution approvals on the shared Maps identity lock', function () {
    config()->set('moderation.review_lock_block_seconds', 1);
    $locality = approvalRecheckLocality();
    $create = approvalRecheckStagedCreate('Locked Mosque', $locality + ['google_place_id' => 'locked_place']);

    // Pin the exact shared key the submit path uses so approvals cannot slip
    // past a concurrent submit or approval for the same Maps identity.
    $lock = Cache::lock('contribution-create:institution-maps:'.hash('sha256', 'place:locked_place'), 30);
    expect($lock->acquire())->toBeTrue();

    try {
        expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($create, User::factory()->create()))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }

    expect($create->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('blocks approving a name-only update that duplicates a name in the same locality', function () {
    $locality = approvalRecheckLocality();
    $holder = Institution::factory()->create(['name' => 'Masjid Taken Name', 'status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($holder, $locality, allowCountryOnly: true);
    $target = Institution::factory()->create(['name' => 'Masjid Old Name', 'status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($target, $locality, allowCountryOnly: true);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $target->getKey(),
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Masjid Taken Name'],
    ]);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($update, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    expect($update->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('approves a name-only update with a unique name', function () {
    $locality = approvalRecheckLocality();
    $target = Institution::factory()->create(['name' => 'Masjid Old Name', 'status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($target, $locality, allowCountryOnly: true);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $target->getKey(),
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Masjid Unique Name'],
    ]);

    $approved = app(ApproveContributionRequestAction::class)->handle($update, User::factory()->create());

    expect($approved->status)->toBe(ContributionRequestStatus::Approved)
        ->and($target->fresh()?->name)->toBe('Masjid Unique Name');
});

it('audits a staged create against its entity current identity instead of stale proposed data', function () {
    $locality = approvalRecheckLocality();
    $holder = Institution::factory()->create(['name' => 'Holder Mosque', 'status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($holder, $locality + ['google_place_id' => 'occupied_place'], allowCountryOnly: true);

    $staged = Institution::factory()->create(['name' => 'Corrected Mosque', 'status' => InstitutionStatus::Pending]);
    SharedFormSchema::createAddressFromData($staged, $locality + ['google_place_id' => 'occupied_place'], allowCountryOnly: true);

    $create = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $staged->getKey(),
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Corrected Mosque',
            'address' => $locality + ['google_place_id' => 'stale_free_place'],
        ],
    ]);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($create, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    $moved = Institution::factory()->create(['name' => 'Moved Mosque', 'status' => InstitutionStatus::Pending]);
    SharedFormSchema::createAddressFromData($moved, $locality + ['google_place_id' => 'free_place'], allowCountryOnly: true);

    $cleanCreate = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $moved->getKey(),
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Moved Mosque',
            'address' => $locality + ['google_place_id' => 'occupied_place'],
        ],
    ]);

    $approved = app(ApproveContributionRequestAction::class)->handle($cleanCreate, User::factory()->create());

    expect($approved->status)->toBe(ContributionRequestStatus::Approved);
});

it('blocks approving an update that moves onto a pending create claim', function () {
    $locality = approvalRecheckLocality();

    ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => null,
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Claimed By Create',
            'address' => $locality + ['google_place_id' => 'create_claimed_place'],
        ],
    ]);

    $moving = Institution::factory()->create(['name' => 'Moving Mosque', 'status' => InstitutionStatus::Verified]);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $moving->getKey(),
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['address' => $locality + ['google_place_id' => 'create_claimed_place']],
    ]);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($update, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    expect($update->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('rejects an already-reviewed approval instead of silently succeeding', function () {
    $locality = approvalRecheckLocality();
    $create = approvalRecheckStagedCreate('Idempotent Mosque', $locality + ['google_place_id' => 'idempotent_place']);
    $firstReviewer = User::factory()->create();

    // A second reviewer loads the request while it is still pending, then
    // approves after the first approval has landed.
    $staleView = ContributionRequest::query()->findOrFail($create->getKey());

    app(ApproveContributionRequestAction::class)->handle($create, $firstReviewer, 'First review wins.');

    $approved = $create->fresh();
    assert($approved instanceof ContributionRequest);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($staleView, User::factory()->create()))
        ->toThrow(ContributionRequestAlreadyReviewedException::class, __('This request has already been reviewed. Please refresh to see its current status.'));

    expect($create->fresh()?->status)->toBe(ContributionRequestStatus::Approved)
        ->and($create->fresh()?->reviewer_id)->toBe($firstReviewer->getKey())
        ->and($create->fresh()?->reviewer_note)->toBe('First review wins.')
        ->and((string) $create->fresh()?->reviewed_at)->toBe((string) $approved->reviewed_at)
        ->and((string) $create->fresh()?->approved_at)->toBe((string) $approved->approved_at);
});

function approvalRecheckAdminUser(): User
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::query()->where('name', 'moderator')->where('guard_name', 'web')->exists()) {
        (new Role)->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'moderator',
            'guard_name' => 'web',
        ])->save();
    }

    $admin = User::factory()->create();
    $admin->assignRole('moderator');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($admin);

    return $admin;
}

it('returns 422 with the conflict message when the admin API approves a conflicting request', function () {
    approvalRecheckAdminUser();

    $locality = approvalRecheckLocality();
    $first = approvalRecheckStagedCreate('First Mosque', $locality + ['google_place_id' => 'api_contested_place']);
    approvalRecheckStagedCreate('Second Mosque', $locality + ['google_place_id' => 'api_contested_place']);

    $response = $this->postJson('/api/v1/admin/contribution-requests/'.$first->getRouteKey().'/review', [
        'action' => 'approve',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('message', approvalRecheckConflictMessage())
        ->assertJsonPath('errors.reviewer_note.0', approvalRecheckConflictMessage());

    expect($first->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('blocks approving an update that moves an institution into a taken name and locality', function () {
    $locality = approvalRecheckLocality();
    $otherLocality = createTestPackageGeography(stateName: 'Johor', districtName: 'Johor Bahru', cityName: 'Johor Bahru');
    $otherLocalityIds = [
        'country_id' => (string) $otherLocality['country']->getKey(),
        'state_id' => (string) $otherLocality['state']->getKey(),
        'city_id' => (string) $otherLocality['city']->getKey(),
    ];

    $holder = Institution::factory()->create(['name' => 'Masjid Branch', 'status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($holder, $locality, allowCountryOnly: true);
    $moving = Institution::factory()->create(['name' => 'Masjid Branch', 'status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($moving, $otherLocalityIds, allowCountryOnly: true);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $moving->getKey(),
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['address' => $locality + ['google_place_id' => 'branch_new_place']],
    ]);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($update, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    expect($update->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('rejects a stale rejection after the request was approved', function () {
    $locality = approvalRecheckLocality();
    $create = approvalRecheckStagedCreate('Stale Reject Mosque', $locality + ['google_place_id' => 'stale_reject_place']);
    $staleView = ContributionRequest::query()->findOrFail($create->getKey());

    app(ApproveContributionRequestAction::class)->handle($create, User::factory()->create());

    expect(fn (): ContributionRequest => app(RejectContributionRequestAction::class)->handle($staleView, User::factory()->create(), 'needs_more_evidence'))
        ->toThrow(ContributionRequestNotPendingException::class, 'Only pending contribution requests can be rejected.');

    expect($create->fresh()?->status)->toBe(ContributionRequestStatus::Approved);
});

it('rejects a stale rejection after the request was already rejected', function () {
    $locality = approvalRecheckLocality();
    $create = approvalRecheckStagedCreate('Twice Reject Mosque', $locality + ['google_place_id' => 'twice_reject_place']);
    $staleView = ContributionRequest::query()->findOrFail($create->getKey());

    app(RejectContributionRequestAction::class)->handle($create, User::factory()->create(), 'needs_more_evidence');

    expect(fn (): ContributionRequest => app(RejectContributionRequestAction::class)->handle($staleView, User::factory()->create(), 'duplicate'))
        ->toThrow(ContributionRequestNotPendingException::class, 'Only pending contribution requests can be rejected.');

    expect($create->fresh()?->status)->toBe(ContributionRequestStatus::Rejected);
});

it('approves flat proposed payloads carrying Maps keys outside the address array', function () {
    $locality = approvalRecheckLocality();
    $holder = Institution::factory()->create(['name' => 'Flat Holder', 'status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($holder, $locality + ['google_place_id' => 'flat_place'], allowCountryOnly: true);
    $moving = Institution::factory()->create(['name' => 'Flat Mover', 'status' => InstitutionStatus::Verified]);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $moving->getKey(),
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['address' => [], 'google_place_id' => 'flat_place'],
    ]);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($update, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    expect($update->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('treats a blank proposed name as absent when an update moves locality', function () {
    $locality = approvalRecheckLocality();
    $holder = Institution::factory()->create(['name' => 'Masjid Blank Move', 'status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($holder, $locality + ['google_place_id' => 'blank_holder_place'], allowCountryOnly: true);
    $moving = Institution::factory()->create(['name' => 'Masjid Blank Move', 'status' => InstitutionStatus::Verified]);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $moving->getKey(),
        'proposer_id' => User::factory()->create(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => '   ', 'address' => $locality + ['google_place_id' => 'blank_new_place']],
    ]);

    expect(fn (): ContributionRequest => app(ApproveContributionRequestAction::class)->handle($update, User::factory()->create()))
        ->toThrow(ValidationException::class, approvalRecheckConflictMessage());

    expect($update->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('returns 503 when the Maps identity lock is held during admin API approval', function () {
    config()->set('moderation.review_lock_block_seconds', 1);
    approvalRecheckAdminUser();

    $locality = approvalRecheckLocality();
    $create = approvalRecheckStagedCreate('Busy API Mosque', $locality + ['google_place_id' => 'busy_api_place']);

    $lock = Cache::lock('contribution-create:institution-maps:'.hash('sha256', 'place:busy_api_place'), 30);
    expect($lock->acquire())->toBeTrue();

    try {
        $response = $this->postJson('/api/v1/admin/contribution-requests/'.$create->getRouteKey().'/review', [
            'action' => 'approve',
        ]);

        $response->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'service_unavailable');
    } finally {
        $lock->release();
    }

    expect($create->fresh()?->status)->toBe(ContributionRequestStatus::Pending);
});

it('returns 409 when the admin API approves an already-decided request', function () {
    approvalRecheckAdminUser();

    $locality = approvalRecheckLocality();
    $create = approvalRecheckStagedCreate('Decided API Mosque', $locality + ['google_place_id' => 'decided_api_place']);

    $this->postJson('/api/v1/admin/contribution-requests/'.$create->getRouteKey().'/review', [
        'action' => 'approve',
    ])->assertOk();

    $response = $this->postJson('/api/v1/admin/contribution-requests/'.$create->getRouteKey().'/review', [
        'action' => 'approve',
    ]);

    $response->assertConflict()
        ->assertJsonPath('error.code', 'conflict');

    expect($create->fresh()?->status)->toBe(ContributionRequestStatus::Approved);
});
