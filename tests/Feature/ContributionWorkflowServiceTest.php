<?php

use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Contributions\CancelContributionRequestAction;
use App\Actions\Contributions\RejectContributionRequestAction;
use App\Actions\Contributions\SubmitContributionCreateRequestAction;
use App\Actions\Contributions\SubmitContributionUpdateRequestAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Support\Authz\MemberPermissionGate;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('stores pending institution create requests for authenticated proposers', function () {
    $proposer = User::factory()->create();

    $request = app(SubmitContributionCreateRequestAction::class)->handle(
        ContributionSubjectType::Institution,
        $proposer,
        [
            'name' => 'Masjid Al-Hikmah',
            'type' => 'masjid',
            'description' => 'Community masjid',
        ],
        'Please add this institution.',
    );

    expect($request->type)->toBe(ContributionRequestType::Create)
        ->and($request->subject_type)->toBe(ContributionSubjectType::Institution)
        ->and($request->status)->toBe(ContributionRequestStatus::Pending)
        ->and($request->proposer_id)->toBe($proposer->id)
        ->and($request->entity_id)->toBeNull()
        ->and($request->proposed_data)->toMatchArray([
            'name' => 'Masjid Al-Hikmah',
            'type' => 'masjid',
        ]);
});

it('creates staged pending institution records with structured relation data', function () {
    $proposer = User::factory()->create();

    $country = ensureTestMalaysiaCountry();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Al-Bayan',
        'type' => 'masjid',
        'description' => 'Pusat ilmu masyarakat.',
        'address' => [
            'line1' => 'Jalan Hikmah',
            'country_id' => (string) $country->getKey(),
        ],
        'contactMethods' => [[
            'category' => 'phone',
            'value' => '0123456789',
            'is_public' => true,
        ]],
    ], $proposer);

    expect($institution->status)->toBe('pending')
        ->and($institution->primaryAddress()?->line1)->toBe('Jalan Hikmah')
        ->and($institution->contactMethods()->where('value', '0123456789')->exists())->toBeTrue()
        ->and($institution->members()->whereKey($proposer->id)->exists())->toBeFalse();
});

it('creates staged pending speaker records with structured relation data', function () {
    $proposer = User::factory()->create();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Arif',
        'gender' => 'male',
        'bio' => ['type' => 'doc', 'content' => []],
    ], $proposer);

    expect($person->status)->toBe('pending')
        ->and($person->members()->whereKey($proposer->id)->exists())->toBeTrue();
});

it('approves institution create requests without attaching proposer membership and notifies the proposer', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Masjid Al-Hidayah',
            'type' => 'masjid',
            'description' => 'Approved by moderator',
        ],
        'original_data' => null,
    ]);

    $approvedRequest = app(ApproveContributionRequestAction::class)->handle($request, $reviewer, 'Looks legitimate.');
    $institution = Institution::findOrFail($approvedRequest->entity_id);

    expect($approvedRequest->status)->toBe(ContributionRequestStatus::Approved)
        ->and($approvedRequest->reviewer_id)->toBe($reviewer->id)
        ->and($approvedRequest->entity_type)->toBe($institution->getMorphClass())
        ->and($institution->status)->toBe('verified')
        ->and($institution->members()->whereKey($proposer->id)->exists())->toBeFalse()
        ->and(app(MemberPermissionGate::class)->canInstitution($proposer, 'institution.update', $institution))->toBeFalse();

});

it('approves staged institution create requests without creating a duplicate record', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Pending',
        'status' => 'pending',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => $institution->name,
            'type' => 'masjid',
        ],
    ]);

    app(ApproveContributionRequestAction::class)->handle($request, $reviewer, 'Looks legitimate.');

    expect(Institution::query()->where('name', 'Masjid Pending')->count())->toBe(1)
        ->and($institution->fresh()->status)->toBe('verified')
        ->and($institution->fresh()->members()->whereKey($proposer->id)->exists())->toBeFalse();
});

it('rejects institution create requests and notifies the proposer', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Masjid Ditolak',
            'type' => 'masjid',
        ],
        'original_data' => null,
    ]);

    app(RejectContributionRequestAction::class)->handle($request, $reviewer, 'duplicate', 'This institution already exists.');

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Rejected);

});

it('captures original data for update requests and applies approved reference updates', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $reference = Reference::factory()->create([
        'title' => 'Original Title',
        'description' => 'Original description',
    ]);

    $request = app(SubmitContributionUpdateRequestAction::class)->handle(
        $reference,
        $proposer,
        [
            'title' => 'Updated Title',
            'description' => 'Revised description',
        ],
        'Fixing stale metadata.',
    );

    expect($request->original_data)->toMatchArray([
        'title' => 'Original Title',
        'description' => 'Original description',
    ]);

    app(ApproveContributionRequestAction::class)->handle($request, $reviewer, 'Approved update.');

    $reference->refresh();
    $request->refresh();

    expect($request->status)->toBe(ContributionRequestStatus::Approved)
        ->and($reference->title)->toBe('Updated Title')
        ->and($reference->description)->toBe('Revised description');
});

it('applies structured institution updates through approval', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create([
        'description' => 'Old description',
        'status' => 'verified',
    ]);

    $request = app(SubmitContributionUpdateRequestAction::class)->handle(
        $institution,
        $proposer,
        [
            'description' => 'New description',
            'address' => [
                'line1' => 'Jalan Hikmah 5',
                'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
            ],
            'contactMethods' => [[
                'category' => 'phone',
                'value' => '01112345678',
                'is_public' => true,
            ], [
                'category' => 'email',
                'value' => 'contact@masjidhikmah.test',
                'is_public' => true,
            ]],
            'social_media' => [[
                'platform' => 'facebook',
                'url' => 'https://facebook.com/masjidhikmah',
            ], [
                'platform' => 'youtube',
                'url' => 'https://youtube.com/@masjidhikmah',
            ]],
        ],
    );

    app(ApproveContributionRequestAction::class)->handle($request, $reviewer, 'Approved update.');

    $institution->refresh();

    expect($institution->description)->toBe('New description')
        ->and($institution->primaryAddress()?->line1)->toBe('Jalan Hikmah 5')
        ->and($institution->contactMethods->pluck('value')->all())->toEqual(['01112345678', 'contact@masjidhikmah.test'])
        ->and($institution->contactMethods->pluck('sort_order')->all())->toEqual([1, 2])
        ->and($institution->socialProfiles->pluck('platform')->all())->toEqual(['facebook', 'youtube'])
        ->and($institution->socialProfiles->pluck('sort_order')->all())->toEqual([1, 2]);
});

it('applies structured event participant and reference updates through approval', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->create([
        'status' => 'verified',
    ]);

    $request = app(SubmitContributionUpdateRequestAction::class)->handle(
        $event,
        $proposer,
        [
            'title' => 'Kuliah Terkini',
            'reference_ids' => [$reference->id],
            'speaker_ids' => [$person->id],
            'other_key_people' => [[
                'role_code' => 'moderator',
                'display_name' => 'Moderator Test',
                'visibility' => 'public',
            ]],
        ],
    );

    app(ApproveContributionRequestAction::class)->handle($request, $reviewer, 'Approved event update.');

    $event->refresh();

    expect($event->title)->toBe('Kuliah Terkini')
        ->and($event->references()->whereKey($reference->id)->exists())->toBeTrue()
        ->and($event->keyPeople()
            ->where('involveable_type', (new Person)->getMorphClass())
            ->where('involveable_id', $person->id)
            ->exists())->toBeTrue()
        ->and($event->keyPeople()->where('role_code', 'moderator')->exists())->toBeTrue();
});

it('allows proposers to cancel pending requests and stores cancellation time', function () {
    $proposer = User::factory()->create();

    $request = ContributionRequest::factory()->create([
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    app(CancelContributionRequestAction::class)->handle($request, $proposer);

    $request->refresh();

    expect($request->status)->toBe(ContributionRequestStatus::Cancelled)
        ->and($request->cancelled_at)->not->toBeNull();
});
