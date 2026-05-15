<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Contributions\CancelContributionRequestAction;
use App\Actions\Contributions\CanReviewContributionRequestAction;
use App\Actions\Contributions\ResolveContributionChangedPayloadAction;
use App\Actions\Contributions\ResolveContributionEntityMetadataAction;
use App\Actions\Contributions\ResolveContributionSubjectAction;
use App\Actions\Contributions\ResolveContributionSubjectPresentationAction;
use App\Actions\Contributions\ResolveContributionSubmissionStateAction;
use App\Actions\Contributions\ResolveContributionUpdateContextAction;
use App\Actions\Contributions\ResolveLatestPendingContributionRequestAction;
use App\Actions\Contributions\ResolveOwnContributionRequestAction;
use App\Actions\Contributions\ResolvePendingContributionApprovalsAction;
use App\Actions\Contributions\ResolveReviewableContributionRequestAction;
use App\Actions\Contributions\SubmitContributionCreateRequestAction;
use App\Actions\Contributions\SubmitContributionUpdateRequestAction;
use App\Actions\Contributions\SubmitStagedContributionCreateAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function assignInstitutionOwnerForContributionActions(User $user, Institution $institution): void
{
    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    $institution->members()->syncWithoutDetaching([$user->id]);

    Authz::withScope(app(MemberRoleScopes::class)->institution(), function () use ($user): void {
        $user->syncRoles(['owner']);
    }, $user);
}

it('submits contribution create requests through the action layer', function () {
    $proposer = User::factory()->create();

    $request = SubmitContributionCreateRequestAction::run(
        ContributionSubjectType::Institution,
        $proposer,
        [
            'name' => 'Masjid Action',
            'type' => 'masjid',
            'description' => 'Created through action.',
        ],
        'Please review this institution.',
    );

    expect($request->type)->toBe(ContributionRequestType::Create)
        ->and($request->status)->toBe(ContributionRequestStatus::Pending)
        ->and($request->proposer_id)->toBe($proposer->id)
        ->and($request->proposed_data)->toMatchArray([
            'name' => 'Masjid Action',
            'type' => 'masjid',
        ]);
});

it('submits staged contribution create requests with shared entity metadata', function () {
    $proposer = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'pending',
    ]);

    $request = SubmitContributionCreateRequestAction::run(
        ContributionSubjectType::Institution,
        $proposer,
        [
            'name' => $institution->name,
            'type' => 'masjid',
        ],
        'Track staged metadata.',
        $institution,
    );

    expect($request->subject_type)->toBe(ContributionSubjectType::Institution)
        ->and($request->entity_type)->toBe($institution->getMorphClass())
        ->and($request->entity_id)->toBe($institution->id);
});

it('submits staged institution contributions through the action layer', function () {
    $proposer = User::factory()->create();

    $institution = app(SubmitStagedContributionCreateAction::class)->handle(
        ContributionSubjectType::Institution,
        [
            'name' => 'Masjid Beraksi',
            'type' => 'masjid',
            'description' => 'Institution created through staged action.',
            'proposer_note' => 'Please review this institution.',
        ],
        $proposer,
    );

    $request = ContributionRequest::query()->latest('created_at')->first();

    expect($institution->name)->toBe('Masjid Beraksi')
        ->and($institution->status)->toBe('pending')
        ->and($institution->members()->whereKey($proposer->id)->exists())->toBeFalse()
        ->and($request)->not->toBeNull()
        ->and($request?->entity_id)->toBe($institution->id)
        ->and($request?->proposed_data)->toMatchArray([
            'name' => 'Masjid Beraksi',
            'type' => 'masjid',
        ]);
});

it('submits staged speaker contributions through the action layer', function () {
    $proposer = User::factory()->create();

    $speaker = app(SubmitStagedContributionCreateAction::class)->handle(
        ContributionSubjectType::Speaker,
        [
            'name' => 'Speaker Beraksi',
            'gender' => 'male',
            'bio' => 'Speaker created through staged action.',
            'proposer_note' => 'Please review this speaker.',
        ],
        $proposer,
    );

    $request = ContributionRequest::query()->latest('created_at')->first();

    expect($speaker->name)->toBe('Speaker Beraksi')
        ->and($speaker->status)->toBe('pending')
        ->and($request)->not->toBeNull()
        ->and($request?->entity_id)->toBe($speaker->id)
        ->and($request?->proposed_data)->toMatchArray([
            'name' => 'Speaker Beraksi',
            'gender' => 'male',
        ]);
});

it('submits contribution update requests through the action layer', function () {
    $proposer = User::factory()->create();
    $reference = Reference::factory()->create([
        'title' => 'Original Action Title',
        'description' => 'Original description.',
    ]);

    $request = SubmitContributionUpdateRequestAction::run(
        $reference,
        $proposer,
        [
            'title' => 'Updated Action Title',
            'description' => 'Updated description.',
        ],
        'Correcting stale content.',
    );

    expect($request->type)->toBe(ContributionRequestType::Update)
        ->and($request->subject_type)->toBe(ContributionSubjectType::Reference)
        ->and($request->original_data)->toMatchArray([
            'title' => 'Original Action Title',
            'description' => 'Original description.',
        ]);
});

it('approves staged institution create requests through the action layer without duplication', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Action Pending',
        'status' => 'pending',
        'is_active' => true,
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

    $approvedRequest = ApproveContributionRequestAction::run($request, $reviewer, 'Approved via action.');

    expect(Institution::query()->where('name', 'Masjid Action Pending')->count())->toBe(1)
        ->and($approvedRequest->status)->toBe(ContributionRequestStatus::Approved)
        ->and($institution->fresh()->status)->toBe('verified')
        ->and($institution->fresh()->members()->whereKey($proposer->id)->exists())->toBeFalse();
});

it('approves staged create requests when the proposer relation is missing', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Missing Proposer',
        'status' => 'pending',
        'is_active' => true,
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

    $proposer->delete();
    $request->refresh();

    expect($request->proposer)->toBeNull();

    $approvedRequest = ApproveContributionRequestAction::run($request, $reviewer, 'Approved without proposer.');

    expect($approvedRequest->status)->toBe(ContributionRequestStatus::Approved)
        ->and($institution->fresh()->status)->toBe('verified');
});

it('cancels pending contribution requests through the action layer', function () {
    $proposer = User::factory()->create();

    $request = ContributionRequest::factory()->create([
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    $cancelledRequest = CancelContributionRequestAction::run($request, $proposer);

    expect($cancelledRequest->status)->toBe(ContributionRequestStatus::Cancelled)
        ->and($cancelledRequest->cancelled_at)->not->toBeNull();
});

it('resolves contribution update context from slug and uuid subjects', function () {
    $speaker = Speaker::factory()->create([
        'slug' => 'speaker-action-subject',
        'name' => 'Speaker Action Subject',
        'bio' => 'Speaker bio.',
    ])->fresh();
    $event = Event::factory()->create([
        'title' => 'Action Event',
        'slug' => 'action-event',
    ]);

    $speakerContext = app(ResolveContributionUpdateContextAction::class)->handle('penceramah', (string) $speaker->slug);
    $eventContext = app(ResolveContributionUpdateContextAction::class)->handle('majlis', 'action-event');

    expect($speakerContext['entity']->is($speaker))->toBeTrue()
        ->and($speakerContext['initial_state'])->toHaveKey('name', 'Speaker Action Subject')
        ->and($eventContext['entity']->is($event))->toBeTrue()
        ->and($eventContext['initial_state'])->toHaveKey('title', 'Action Event');
});

it('resolves contribution subjects from slug and uuid identifiers through the action layer', function () {
    $institution = Institution::factory()->create([
        'slug' => 'institusi-tindakan',
        'name' => 'Institusi Tindakan',
    ])->fresh();
    $reference = Reference::factory()->create([
        'slug' => 'tafsir-tindakan',
    ]);

    $resolvedInstitution = app(ResolveContributionSubjectAction::class)->handle('institution', (string) $institution->slug);
    $resolvedReference = app(ResolveContributionSubjectAction::class)->handle('rujukan', 'tafsir-tindakan');

    expect($resolvedInstitution->is($institution))->toBeTrue()
        ->and($resolvedReference->is($reference))->toBeTrue();
});

it('resolves contribution entity metadata through the action layer', function () {
    $event = Event::factory()->create();

    $metadata = app(ResolveContributionEntityMetadataAction::class)->handle($event);

    expect($metadata)->toBe([
        'subject_type' => ContributionSubjectType::Event,
        'entity_type' => $event->getMorphClass(),
        'entity_id' => (string) $event->getKey(),
    ]);
});

it('resolves the latest pending contribution request for a proposer and entity', function () {
    $proposer = User::factory()->create();
    $institution = Institution::factory()->create();
    $olderRequest = ContributionRequest::factory()->create([
        'proposer_id' => $proposer->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
        'created_at' => now()->subDay(),
    ]);
    $latestRequest = ContributionRequest::factory()->create([
        'proposer_id' => $proposer->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    $resolvedRequest = app(ResolveLatestPendingContributionRequestAction::class)->handle($proposer, $institution);

    expect($resolvedRequest?->is($latestRequest))->toBeTrue()
        ->and($resolvedRequest?->is($olderRequest))->toBeFalse();
});

it('resolves contribution subject presentation through the action layer', function () {
    $speaker = Speaker::factory()->create();

    $presentation = app(ResolveContributionSubjectPresentationAction::class)->handle($speaker);

    expect($presentation['subject_label'])->toBe(__('Speaker'))
        ->and($presentation['redirect_url'])->toBe(route('speakers.show', $speaker));
});

it('resolves changed contribution payloads through the action layer', function () {
    $startsAt = Carbon::parse('2026-05-01 12:00:00', 'UTC');

    $changes = app(ResolveContributionChangedPayloadAction::class)->handle(
        [
            'visibility' => 'public',
            'starts_at' => $startsAt,
            'disciplines' => Collection::make(['aqidah', 'fiqh']),
            'address' => [
                'line1' => 'New line',
            ],
            'ignored_field' => 'skip me',
        ],
        [
            'visibility' => 'public',
            'starts_at' => $startsAt->toISOString(),
            'disciplines' => ['aqidah', 'fiqh'],
            'address' => [
                'line1' => 'Old line',
            ],
        ],
    );

    expect($changes)->toBe([
        'address' => [
            'line1' => 'New line',
        ],
    ]);
});

it('resolves contribution submission state through the action layer', function () {
    $submissionState = app(ResolveContributionSubmissionStateAction::class)->handle([
        'name' => 'Masjid Refactor',
        'proposer_note' => '  Please review this carefully.  ',
        'description' => 'Normalized through action.',
    ]);

    expect($submissionState)->toBe([
        'state' => [
            'name' => 'Masjid Refactor',
            'description' => 'Normalized through action.',
        ],
        'proposer_note' => 'Please review this carefully.',
    ]);
});

it('determines whether a user may review a contribution request through the action layer', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    assignInstitutionOwnerForContributionActions($owner, $institution);

    expect(app(CanReviewContributionRequestAction::class)->handle($owner, $request))->toBeTrue()
        ->and(app(CanReviewContributionRequestAction::class)->handle($stranger, $request))->toBeFalse();
});

it('resolves only reviewable pending contribution approvals through the action layer', function () {
    $owner = User::factory()->create();
    $proposer = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $reviewableRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);
    $hiddenCreateRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    assignInstitutionOwnerForContributionActions($owner, $institution);

    $pendingApprovals = app(ResolvePendingContributionApprovalsAction::class)->handle($owner);

    expect($pendingApprovals->pluck('id')->all())->toBe([$reviewableRequest->id])
        ->and($pendingApprovals->contains(fn (ContributionRequest $request): bool => $request->is($hiddenCreateRequest)))->toBeFalse();
});

it('resolves reviewable contribution requests through the action layer', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    assignInstitutionOwnerForContributionActions($owner, $institution);

    $resolvedRequest = app(ResolveReviewableContributionRequestAction::class)->handle($owner, $request->id);

    expect($resolvedRequest->is($request))->toBeTrue();

    expect(fn () => app(ResolveReviewableContributionRequestAction::class)->handle($stranger, $request->id))
        ->toThrow(HttpException::class);
});

it('resolves only the proposer owned contribution request for cancel flows', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $request = ContributionRequest::factory()->create([
        'proposer_id' => $owner->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    $resolvedRequest = app(ResolveOwnContributionRequestAction::class)->handle($owner, $request->id);

    expect($resolvedRequest->is($request))->toBeTrue();

    expect(fn () => app(ResolveOwnContributionRequestAction::class)->handle($stranger, $request->id))
        ->toThrow(ModelNotFoundException::class);
});
