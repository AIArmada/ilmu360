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
use App\Models\Reference;
use App\Models\Speaker;
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

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Al-Bayan',
        'type' => 'masjid',
        'description' => 'Pusat ilmu masyarakat.',
        'address' => [
            'line1' => 'Jalan Hikmah',
            'state_id' => 1,
        ],
        'contacts' => [[
            'category' => 'phone',
            'value' => '0123456789',
            'type' => 'main',
            'is_public' => true,
        ]],
    ], $proposer);

    expect($institution->status)->toBe('pending')
        ->and($institution->addressModel?->line1)->toBe('Jalan Hikmah')
        ->and($institution->contacts()->where('value', '0123456789')->exists())->toBeTrue()
        ->and($institution->members()->whereKey($proposer->id)->exists())->toBeFalse();
});

it('creates staged pending speaker records with structured relation data', function () {
    $proposer = User::factory()->create();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Arif',
        'gender' => 'male',
        'job_title' => 'Pendakwah',
        'bio' => ['type' => 'doc', 'content' => []],
        'qualifications' => [[
            'institution' => 'Universiti Islam',
            'degree' => 'MA',
            'field' => 'Dakwah',
            'year' => '2020',
        ]],
    ], $proposer);

    expect($speaker->status)->toBe('pending')
        ->and($speaker->job_title)->toBe('Pendakwah')
        ->and($speaker->qualifications)->toBeArray()
        ->and($speaker->members()->whereKey($proposer->id)->exists())->toBeTrue();
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

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $proposer->id,
        'trigger' => 'submission_approved',
    ]);
});

it('approves staged institution create requests without creating a duplicate record', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Pending',
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

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $proposer->id,
        'trigger' => 'submission_rejected',
    ]);
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
                'state_id' => 1,
            ],
            'contacts' => [[
                'category' => 'phone',
                'value' => '01112345678',
                'type' => 'main',
                'is_public' => true,
            ], [
                'category' => 'email',
                'value' => 'contact@masjidhikmah.test',
                'type' => 'main',
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
        ->and($institution->addressModel?->line1)->toBe('Jalan Hikmah 5')
        ->and($institution->contacts->pluck('value')->all())->toEqual(['01112345678', 'contact@masjidhikmah.test'])
        ->and($institution->contacts->pluck('order_column')->all())->toEqual([1, 2])
        ->and($institution->socialMedia->pluck('platform')->all())->toEqual(['facebook', 'youtube'])
        ->and($institution->socialMedia->pluck('order_column')->all())->toEqual([1, 2]);
});

it('applies structured event participant and reference updates through approval', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'is_active' => true,
        'visibility' => 'public',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);
    $reference = Reference::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $request = app(SubmitContributionUpdateRequestAction::class)->handle(
        $event,
        $proposer,
        [
            'title' => 'Kuliah Terkini',
            'reference_ids' => [$reference->id],
            'speaker_ids' => [$speaker->id],
            'other_key_people' => [[
                'role' => 'moderator',
                'name' => 'Moderator Test',
                'is_public' => true,
            ]],
        ],
    );

    app(ApproveContributionRequestAction::class)->handle($request, $reviewer, 'Approved event update.');

    $event->refresh();

    expect($event->title)->toBe('Kuliah Terkini')
        ->and($event->references()->whereKey($reference->id)->exists())->toBeTrue()
        ->and($event->keyPeople()->where('speaker_id', $speaker->id)->exists())->toBeTrue()
        ->and($event->keyPeople()->where('role', 'moderator')->where('name', 'Moderator Test')->exists())->toBeTrue();
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
