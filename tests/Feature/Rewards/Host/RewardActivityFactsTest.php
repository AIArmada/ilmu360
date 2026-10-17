<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Rewards\Actions\ApplyRewardSourceCorrectionsAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\PublishRewardProgramVersionAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardMilestoneStatus;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardCapCounter;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardMilestoneCycle;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Rewards\Models\RewardSourceComponentClaim;
use App\Actions\Rewards\EnrollRewardProgramMemberAction as HostEnrollRewardProgramMemberAction;
use App\Enums\DawahShareOutcomeType;
use App\Livewire\Pages\Dashboard\RewardMemberIndex;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Rewards\ActivityRewardSourceFactReader;
use App\Support\Rewards\ShareAttributionRewardSourceFactReader;
use App\Support\ShareTracking\ActivityRewardBusinessKey;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

/**
 * @return array{0: RewardProgram, 1: RewardProgramVersion}
 */
function seedActivityRewardProgram(string $prefix, User ...$members): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram(
        $prefix,
        RewardProgramMode::Points,
        ['eligibility' => ['outcomes' => ['event_registration', 'event_checkin']]],
    );

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    foreach ($members as $member) {
        EnrollRewardMemberAction::run(
            $program->refresh(),
            'user',
            (string) $member->getKey(),
            $version,
            'member-portal',
            CarbonImmutable::now('UTC')->subDay(),
        );
    }

    return [$program->refresh(), $version];
}

/**
 * @param  array<string, mixed>  $ruleOverrides
 * @return array{0: RewardProgram, 1: RewardProgramVersion}
 */
function seedActivityRewardProgramWithRules(
    string $prefix,
    array $ruleOverrides,
    RewardProgramMode $mode,
    User ...$members,
): array {
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, $mode, $ruleOverrides);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    foreach ($members as $member) {
        EnrollRewardMemberAction::run(
            $program->refresh(),
            'user',
            (string) $member->getKey(),
            $version,
            'member-portal',
            CarbonImmutable::now('UTC')->subDay(),
        );
    }

    return [$program->refresh(), $version];
}

/**
 * @return array{request: Request, attribution_id: string, affiliate_id: string, affiliate_link_id: string}
 */
function activityShareRequest(User $sharer): array
{
    $tracking = app(ShareTrackingService::class);
    $pageUrl = route('events.index');
    $tracking->createOrReuseLink($sharer, $pageUrl, 'Activity rewards', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();
    $link = AffiliateLink::query()
        ->where('affiliate_id', $affiliate->getKey())
        ->firstOrFail();

    $landing = Request::create($pageUrl.'?share='.$link->custom_slug, 'GET');
    $route = app('router')->getRoutes()->match($landing);
    $landing->setRouteResolver(static fn (): mixed => $route);
    $cookie = $tracking->captureRequest($landing);
    $request = Request::create($pageUrl);
    $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), $cookie);

    $attribution = $tracking->resolveActiveAttribution($request);

    if ($attribution === null) {
        throw new RuntimeException('The captured share link did not resolve to an active attribution.');
    }

    return [
        'request' => $request,
        'attribution_id' => $attribution->id,
        'affiliate_id' => (string) $affiliate->getKey(),
        'affiliate_link_id' => (string) $link->getKey(),
    ];
}

/**
 * @return array{participant: int, sharer: int}
 */
function activityAwardCounts(string $businessKey, User $actor, User $sharer): array
{
    $base = RewardAward::query()
        ->where('source_business_key', $businessKey)
        ->where('component', RewardComponent::Points->value);

    return [
        'participant' => (clone $base)
            ->where('recipient_id', (string) $actor->getKey())
            ->where('beneficiary_role', RewardBeneficiaryRole::Participant->value)
            ->count(),
        'sharer' => (clone $base)
            ->where('recipient_id', (string) $sharer->getKey())
            ->where('beneficiary_role', RewardBeneficiaryRole::Sharer->value)
            ->count(),
    ];
}

function evaluateHostRewardFact(
    RewardProgram $program,
    string $businessKey,
    string $recipientId,
    RewardBeneficiaryRole $role,
): void {
    (new EvaluateRewardEntitlementAction(app(RewardSourceFactReader::class)))->handle(
        $program->refresh(),
        RewardComponent::Points,
        'user',
        $recipientId,
        $role,
        $businessKey,
        CarbonImmutable::now('UTC'),
    );
}

function evaluateScopedRewardJob(
    string $businessKey,
    ?User $member = null,
    ?RewardBeneficiaryRole $role = null,
    ?RewardProgram $program = null,
): void {
    $reader = app(RewardSourceFactReader::class);
    $job = new EvaluateRewardEntitlementJob(
        $businessKey,
        $member instanceof User ? (string) $member->getKey() : null,
        $role,
        $member instanceof User ? $member->getMorphClass() : null,
        $program instanceof RewardProgram ? (string) $program->getAttribute('family_key') : null,
    );

    $job->handle($reader, new EvaluateRewardEntitlementAction($reader));
}

it('reads an unattributed registration directly from its authenticated registrant', function (): void {
    $actor = User::factory()->create();
    $registration = Registration::factory()->forRegistrant($actor)->create([
        'status' => 'confirmed',
        'total_amount' => 0,
        'payment_status' => 'unpaid',
    ]);
    $key = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventRegistration,
        'registration:'.(string) $registration->getKey(),
    );

    $fact = (new ActivityRewardSourceFactReader)->read($key);

    expect($fact)->not->toBeNull()
        ->and($fact->sourceBusinessKey)->toBe($key)
        ->and($fact->outcome)->toBe(DawahShareOutcomeType::EventRegistration->value)
        ->and($fact->actorId)->toBe((string) $actor->getKey())
        ->and($fact->sharerId)->toBeNull()
        ->and($fact->actorRole)->toBe(RewardBeneficiaryRole::Participant)
        ->and($fact->isAttributed)->toBeFalse()
        ->and($fact->isClaimedIdentity)->toBeTrue()
        ->and($fact->isFirstTime)->toBeTrue()
        ->and($fact->verification)->toBe(RewardVerificationLevel::Accepted)
        ->and($fact->isValid)->toBeTrue();
});

it('awards direct registrations and check-ins once to the participant', function (): void {
    Queue::fake([EvaluateRewardEntitlementJob::class]);

    $actor = User::factory()->create();
    $staff = User::factory()->create();
    [$program] = seedActivityRewardProgram('activity-direct', $actor);

    $registration = Registration::factory()->forRegistrant($actor)->create([
        'status' => 'confirmed',
        'total_amount' => 0,
        'payment_status' => 'unpaid',
    ]);
    $registrationKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
    $registrationActivityKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventRegistration,
        'registration:'.(string) $registration->getKey(),
    );

    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        $registrationKey,
        actor: $actor,
        occurredAt: $registration->registered_at,
    );

    $checkin = EventCheckin::factory()->create([
        'event_id' => $registration->event_id,
        'event_registration_id' => $registration->getKey(),
        'attendee_type' => $actor->getMorphClass(),
        'attendee_id' => $actor->getKey(),
        'verified_by_user_id' => $staff->getKey(),
        'checked_in_at' => CarbonImmutable::now('UTC'),
        'attendance_type' => 'check_in',
    ]);
    $checkinKey = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());
    $checkinActivityKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventCheckin,
        'checkin:'.(string) $checkin->getKey(),
    );

    app(ShareTrackingService::class)->recordInheritedOutcome(
        DawahShareOutcomeType::EventCheckin,
        $checkinKey,
        $registrationKey,
        actor: $actor,
        occurredAt: $checkin->checked_in_at,
    );

    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $registrationActivityKey);
    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $checkinActivityKey);
    Queue::assertPushedTimes(EvaluateRewardEntitlementJob::class, 4);

    evaluateHostRewardFact($program, $registrationActivityKey, (string) $actor->getKey(), RewardBeneficiaryRole::Participant);
    evaluateHostRewardFact($program, $checkinActivityKey, (string) $actor->getKey(), RewardBeneficiaryRole::Participant);
    evaluateHostRewardFact($program, $registrationActivityKey, (string) $actor->getKey(), RewardBeneficiaryRole::Participant);
    evaluateHostRewardFact($program, $checkinActivityKey, (string) $actor->getKey(), RewardBeneficiaryRole::Participant);

    expect(RewardAward::query()
        ->whereIn('source_business_key', [$registrationActivityKey, $checkinActivityKey])
        ->where('recipient_id', (string) $actor->getKey())
        ->where('beneficiary_role', RewardBeneficiaryRole::Participant->value)
        ->where('component', RewardComponent::Points->value)
        ->count())->toBe(2);
});

it('awards distinct host point amounts for signup and staff-verified check-in', function (): void {
    config(['rewards.outcomes' => array_values(array_unique([
        ...config('rewards.outcomes', []),
        DawahShareOutcomeType::Signup->value,
        DawahShareOutcomeType::EventCheckin->value,
    ]))]);

    $actor = User::factory()->create();
    $staff = User::factory()->create();
    [$program] = seedActivityRewardProgramWithRules('activity-outcome-amounts', [
        'components' => [[
            'type' => 'points',
            'variants' => [
                [
                    'outcomes' => [DawahShareOutcomeType::Signup->value],
                    'roles' => [RewardBeneficiaryRole::Participant->value],
                    'min_verification' => RewardVerificationLevel::Accepted->value,
                    'first_only' => true,
                    'points' => 3,
                    'unit' => 'pts',
                ],
                [
                    'outcomes' => [DawahShareOutcomeType::EventCheckin->value],
                    'roles' => [RewardBeneficiaryRole::Participant->value],
                    'min_verification' => RewardVerificationLevel::StaffVerified->value,
                    'first_only' => false,
                    'points' => 17,
                    'unit' => 'pts',
                ],
            ],
        ]],
        'eligibility' => [
            'outcomes' => [DawahShareOutcomeType::Signup->value, DawahShareOutcomeType::EventCheckin->value],
            'min_verification' => RewardVerificationLevel::Accepted->value,
            'first_only' => false,
        ],
    ], RewardProgramMode::Points, $actor);

    $shareTracking = app(ShareTrackingService::class);
    $shareTracking->recordSignup($actor);
    $signupKey = ActivityRewardBusinessKey::for(DawahShareOutcomeType::Signup, 'user:'.(string) $actor->getKey());
    evaluateHostRewardFact($program, $signupKey, (string) $actor->getKey(), RewardBeneficiaryRole::Participant);

    $registration = Registration::factory()->forRegistrant($actor)->create([
        'status' => 'confirmed',
        'total_amount' => 0,
        'payment_status' => 'unpaid',
    ]);
    $registrationShareKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
    $shareTracking->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        $registrationShareKey,
        actor: $actor,
        occurredAt: $registration->registered_at,
    );
    $checkin = EventCheckin::factory()->create([
        'event_id' => $registration->event_id,
        'event_registration_id' => $registration->getKey(),
        'attendee_type' => $actor->getMorphClass(),
        'attendee_id' => $actor->getKey(),
        'verified_by_user_id' => $staff->getKey(),
        'checked_in_at' => CarbonImmutable::now('UTC'),
        'attendance_type' => 'check_in',
    ]);
    $checkinShareKey = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());
    $shareTracking->recordInheritedOutcome(
        DawahShareOutcomeType::EventCheckin,
        $checkinShareKey,
        $registrationShareKey,
        actor: $actor,
        occurredAt: $checkin->checked_in_at,
    );
    $checkinActivityKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventCheckin,
        'checkin:'.(string) $checkin->getKey(),
    );
    evaluateHostRewardFact($program, $checkinActivityKey, (string) $actor->getKey(), RewardBeneficiaryRole::Participant);

    $signupAward = RewardAward::query()->where('source_business_key', $signupKey)->where('recipient_id', (string) $actor->getKey())->firstOrFail();
    $checkinAward = RewardAward::query()->where('source_business_key', $checkinActivityKey)->where('recipient_id', (string) $actor->getKey())->firstOrFail();

    expect($signupAward->amount_snapshot['points'])->toBe(3)
        ->and($checkinAward->amount_snapshot['points'])->toBe(17);
});

it('awards the participant and sharer once each for attributed registration and check-in', function (): void {
    Queue::fake([EvaluateRewardEntitlementJob::class]);

    $actor = User::factory()->create();
    $sharer = User::factory()->create();
    $staff = User::factory()->create();
    $share = activityShareRequest($sharer);
    $request = $share['request'];
    [$program] = seedActivityRewardProgram('activity-attributed', $actor, $sharer);

    $attribution = AffiliateAttribution::query()->whereKey($share['attribution_id'])->firstOrFail();
    $affiliate = Affiliate::query()->whereKey($share['affiliate_id'])->firstOrFail();

    expect([$attribution->owner_type, (string) $attribution->owner_id])
        ->toBe([$affiliate->owner_type, (string) $affiliate->owner_id]);

    $registration = Registration::factory()->forRegistrant($actor)->create([
        'status' => 'confirmed',
        'total_amount' => 0,
        'payment_status' => 'unpaid',
    ]);
    $registrationShareKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
    $registrationOutcome = app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        $registrationShareKey,
        actor: $actor,
        request: $request,
        occurredAt: $registration->registered_at,
    );

    expect($registrationOutcome)->not->toBeNull();

    $checkin = EventCheckin::factory()->create([
        'event_id' => $registration->event_id,
        'event_registration_id' => $registration->getKey(),
        'attendee_type' => $actor->getMorphClass(),
        'attendee_id' => $actor->getKey(),
        'verified_by_user_id' => $staff->getKey(),
        'checked_in_at' => CarbonImmutable::now('UTC'),
        'attendance_type' => 'check_in',
    ]);
    $checkinShareKey = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());
    $checkinOutcome = app(ShareTrackingService::class)->recordInheritedOutcome(
        DawahShareOutcomeType::EventCheckin,
        $checkinShareKey,
        $registrationShareKey,
        actor: $actor,
        occurredAt: $checkin->checked_in_at,
    );

    expect($checkinOutcome)->not->toBeNull();

    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $registrationShareKey);
    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $checkinShareKey);
    Queue::assertPushedTimes(EvaluateRewardEntitlementJob::class, 2);

    foreach ([$registrationShareKey, $checkinShareKey] as $businessKey) {
        evaluateHostRewardFact($program, $businessKey, (string) $actor->getKey(), RewardBeneficiaryRole::Participant);
        evaluateHostRewardFact($program, $businessKey, (string) $sharer->getKey(), RewardBeneficiaryRole::Sharer);
    }

    expect(activityAwardCounts($registrationShareKey, $actor, $sharer))->toBe(['participant' => 1, 'sharer' => 1])
        ->and(activityAwardCounts($checkinShareKey, $actor, $sharer))->toBe(['participant' => 1, 'sharer' => 1]);

    expect(RewardAward::query()
        ->whereIn('source_business_key', [$registrationShareKey, $checkinShareKey])
        ->where('recipient_id', (string) $actor->getKey())
        ->where('beneficiary_role', RewardBeneficiaryRole::Participant->value)
        ->where('component', RewardComponent::Points->value)
        ->count())->toBe(2);
});

it('rejects cancelled registrations and reverses an award when a source is cancelled later', function (): void {
    $actor = User::factory()->create();
    [$program] = seedActivityRewardProgram('activity-corrections', $actor);
    $service = app(ShareTrackingService::class);

    $cancelled = Registration::factory()->forRegistrant($actor)->create([
        'status' => 'cancelled',
        'total_amount' => 0,
        'payment_status' => 'unpaid',
    ]);
    $cancelledKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventRegistration,
        'registration:'.(string) $cancelled->getKey(),
    );
    $service->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        ShareOutcomeBusinessKey::eventRegistration((string) $cancelled->getKey()),
        actor: $actor,
    );
    EvaluateRewardEntitlementJob::dispatchSync($cancelledKey);

    expect(RewardAward::query()->where('source_business_key', $cancelledKey)->exists())->toBeFalse();

    $registration = Registration::factory()->forRegistrant($actor)->create([
        'status' => 'confirmed',
        'total_amount' => 0,
        'payment_status' => 'unpaid',
    ]);
    $activityKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventRegistration,
        'registration:'.(string) $registration->getKey(),
    );
    $service->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey()),
        actor: $actor,
    );
    EvaluateRewardEntitlementJob::dispatchSync($activityKey);

    $award = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', $activityKey)
        ->where('beneficiary_role', RewardBeneficiaryRole::Participant->value)
        ->firstOrFail();

    $registration->transitionStatus('cancelled');
    $report = ApplyRewardSourceCorrectionsAction::run('system', null, 'system');

    expect($report['reversed'])->toContain((string) $award->getKey())
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed);
});

it('completes a private named star milestone from a verified check-in fact', function (): void {
    $actor = User::factory()->create();
    $staff = User::factory()->create();
    [$program] = seedActivityRewardProgramWithRules('activity-bintang', [
        'eligibility' => [
            'outcomes' => [DawahShareOutcomeType::EventCheckin->value],
            'min_verification' => RewardVerificationLevel::StaffVerified->value,
            'first_only' => false,
        ],
        'milestones' => [[
            'key' => 'bintang-1',
            'label' => 'Bintang 1',
            'outcomes' => [DawahShareOutcomeType::EventCheckin->value],
            'roles' => [RewardBeneficiaryRole::Participant->value],
            'min_verification' => RewardVerificationLevel::StaffVerified->value,
            'first_only' => false,
            'target_count' => 1,
            'window_days' => 30,
            'reward' => ['type' => 'badge', 'badge_key' => 'bintang-1'],
        ]],
    ], RewardProgramMode::Combined, $actor);

    $registration = Registration::factory()->forRegistrant($actor)->create([
        'status' => 'confirmed',
        'total_amount' => 0,
        'payment_status' => 'unpaid',
    ]);
    $checkin = EventCheckin::factory()->create([
        'event_id' => $registration->event_id,
        'event_registration_id' => $registration->getKey(),
        'attendee_type' => $actor->getMorphClass(),
        'attendee_id' => $actor->getKey(),
        'verified_by_user_id' => $staff->getKey(),
        'checked_in_at' => CarbonImmutable::now('UTC'),
        'attendance_type' => 'check_in',
    ]);
    $activityKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventCheckin,
        'checkin:'.(string) $checkin->getKey(),
    );

    EvaluateRewardEntitlementJob::dispatchSync($activityKey);

    $cycle = RewardMilestoneCycle::query()
        ->where('program_id', $program->getKey())
        ->where('recipient_id', (string) $actor->getKey())
        ->where('milestone_key', 'bintang-1')
        ->firstOrFail();

    expect($cycle->status)->toBe(RewardMilestoneStatus::Achieved)
        ->and($cycle->current_count)->toBe(1);

    Livewire::actingAs($actor)
        ->test(RewardMemberIndex::class)
        ->assertOk()
        ->assertSee('Bintang 1')
        ->assertSee('1 / 1')
        ->assertSee('private to your account');
});

it('keeps the activity and share namespaces separate and refuses unknown sources', function (): void {
    $registrationId = (string) Str::uuid();
    $activityKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventRegistration,
        'registration:'.$registrationId,
    );
    $shareKey = ShareOutcomeBusinessKey::eventRegistration($registrationId);

    $activityReader = new ActivityRewardSourceFactReader;
    $shareReader = new ShareAttributionRewardSourceFactReader;

    expect($activityReader->read($shareKey))->toBeNull()
        ->and($shareReader->read($activityKey))->toBeNull()
        ->and($activityReader->read($activityKey))->toBeNull();
});

it('dispatches signup activity evaluation after recording the user', function (): void {
    Queue::fake([EvaluateRewardEntitlementJob::class]);
    $user = User::factory()->create();
    $activityKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::Signup,
        'user:'.(string) $user->getKey(),
    );

    app(ShareTrackingService::class)->recordSignup($user);

    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $activityKey);
    Queue::assertPushedTimes(EvaluateRewardEntitlementJob::class, 2);
});

it('backfills eligible preclaim signup facts for both the sharer and participant roles on enrollment', function (): void {
    config(['rewards.outcomes' => array_values(array_unique([
        ...config('rewards.outcomes', []),
        DawahShareOutcomeType::Signup->value,
    ]))]);

    $member = User::factory()->create();
    $visitor = User::factory()->create();
    [$program] = seedActivityRewardProgramWithRules('preclaim-both-roles', [
        'eligibility' => [
            'outcomes' => [DawahShareOutcomeType::Signup->value],
            'min_verification' => RewardVerificationLevel::Accepted->value,
            'first_only' => false,
            'exclude_self_credit' => true,
            'require_enrollment' => true,
            'require_claimed_identity' => true,
        ],
        'windows' => ['preclaim_lookback_days' => 30],
        'components' => [[
            'type' => 'points',
            'variants' => [
                [
                    'outcomes' => [DawahShareOutcomeType::Signup->value],
                    'roles' => [RewardBeneficiaryRole::Participant->value],
                    'min_verification' => RewardVerificationLevel::Accepted->value,
                    'first_only' => true,
                    'points' => 2,
                    'unit' => 'pts',
                ],
                [
                    'outcomes' => [DawahShareOutcomeType::Signup->value],
                    'roles' => [RewardBeneficiaryRole::Sharer->value],
                    'min_verification' => RewardVerificationLevel::Accepted->value,
                    'first_only' => true,
                    'points' => 3,
                    'unit' => 'pts',
                ],
            ],
        ]],
    ], RewardProgramMode::Points);

    $tracking = app(ShareTrackingService::class);
    $tracking->recordSignup($member);
    $share = activityShareRequest($member);
    $tracking->recordSignup($visitor, $share['request']);

    Queue::fake([EvaluateRewardEntitlementJob::class]);
    Sanctum::actingAs($member);
    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $program->current_version_id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    $enrollment = RewardEnrollment::query()
        ->where('program_id', $program->getKey())
        ->where('member_id', (string) $member->getKey())
        ->firstOrFail();
    $participantKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::Signup,
        'user:'.(string) $member->getKey(),
    );
    $sharerKey = ShareOutcomeBusinessKey::signup((string) $visitor->getKey());

    expect($enrollment)->not->toBeNull();

    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $participantKey
        && $job->onlyRecipientId === (string) $member->getKey()
        && $job->onlyRole === RewardBeneficiaryRole::Participant
        && $job->onlyFamilyKey === (string) $program->family_key);
    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $sharerKey
        && $job->onlyRecipientId === (string) $member->getKey()
        && $job->onlyRole === RewardBeneficiaryRole::Sharer
        && $job->onlyFamilyKey === (string) $program->family_key);

    evaluateScopedRewardJob($participantKey, $member, RewardBeneficiaryRole::Participant, $program);
    evaluateScopedRewardJob($sharerKey, $member, RewardBeneficiaryRole::Sharer, $program);

    $participantAward = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', $participantKey)
        ->where('recipient_id', (string) $member->getKey())
        ->where('beneficiary_role', RewardBeneficiaryRole::Participant->value)
        ->firstOrFail();
    $sharerAward = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', $sharerKey)
        ->where('recipient_id', (string) $member->getKey())
        ->where('beneficiary_role', RewardBeneficiaryRole::Sharer->value)
        ->firstOrFail();

    expect($participantAward->amount_snapshot['points'])->toBe(2)
        ->and($sharerAward->amount_snapshot['points'])->toBe(3);

    expect(RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', ShareOutcomeBusinessKey::signup((string) $member->getKey()))
        ->where('recipient_id', (string) $member->getKey())
        ->where('beneficiary_role', RewardBeneficiaryRole::Sharer->value)
        ->exists())->toBeFalse();
});

it('excludes preclaim facts outside the lookback and applies the daily cap across backfill', function (): void {
    $sharer = User::factory()->create();
    [$program, $version] = seedActivityRewardProgramWithRules('preclaim-lookback-cap', [
        'eligibility' => [
            'outcomes' => [DawahShareOutcomeType::EventRegistration->value],
            'min_verification' => RewardVerificationLevel::Accepted->value,
            'first_only' => false,
            'exclude_self_credit' => true,
            'require_enrollment' => true,
            'require_claimed_identity' => true,
        ],
        'windows' => ['preclaim_lookback_days' => 30],
        'caps' => [
            ['scope' => 'recipient_source_component', 'limit' => 1],
            ['scope' => 'recipient_program_day', 'limit' => 2],
        ],
        'components' => [[
            'type' => 'points',
            'variants' => [[
                'outcomes' => [DawahShareOutcomeType::EventRegistration->value],
                'roles' => [RewardBeneficiaryRole::Sharer->value],
                'min_verification' => RewardVerificationLevel::Accepted->value,
                'first_only' => false,
                'points' => 5,
                'unit' => 'pts',
            ]],
        ]],
    ], RewardProgramMode::Points);

    expect($version->refresh()->rules['caps'])->toBe([
        ['scope' => 'recipient_source_component', 'limit' => 1],
        ['scope' => 'recipient_program_day', 'limit' => 2],
    ]);

    $share = activityShareRequest($sharer);
    $tracking = app(ShareTrackingService::class);
    $inWindowKeys = [];

    foreach (range(1, 3) as $number) {
        $actor = User::factory()->create();
        $registration = Registration::factory()->forRegistrant($actor)->create([
            'status' => 'confirmed',
            'total_amount' => 0,
            'payment_status' => 'unpaid',
            'registered_at' => CarbonImmutable::now('UTC')->subMinutes($number),
        ]);
        $shareKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
        $inWindowKeys[] = $shareKey;

        $tracking->recordOutcome(
            DawahShareOutcomeType::EventRegistration,
            $shareKey,
            actor: $actor,
            request: $share['request'],
            occurredAt: $registration->registered_at,
        );
    }

    $oldActor = User::factory()->create();
    $oldRegistration = Registration::factory()->forRegistrant($oldActor)->create([
        'status' => 'confirmed',
        'total_amount' => 0,
        'payment_status' => 'unpaid',
        'registered_at' => CarbonImmutable::now('UTC')->subDays(31),
    ]);
    $oldKey = ShareOutcomeBusinessKey::eventRegistration((string) $oldRegistration->getKey());
    $tracking->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        $oldKey,
        actor: $oldActor,
        request: $share['request'],
        occurredAt: $oldRegistration->registered_at,
    );

    Queue::fake([EvaluateRewardEntitlementJob::class]);
    HostEnrollRewardProgramMemberAction::run($program, $sharer);

    foreach ($inWindowKeys as $businessKey) {
        Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $businessKey
            && $job->onlyRecipientId === (string) $sharer->getKey()
            && $job->onlyRole === RewardBeneficiaryRole::Sharer
            && $job->onlyFamilyKey === (string) $program->family_key);
    }
    Queue::assertNotPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $oldKey
        && $job->onlyRecipientId === (string) $sharer->getKey());

    foreach ($inWindowKeys as $businessKey) {
        evaluateScopedRewardJob($businessKey, $sharer, RewardBeneficiaryRole::Sharer, $program);
    }

    $awards = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->whereIn('source_business_key', [...$inWindowKeys, $oldKey])
        ->where('recipient_id', (string) $sharer->getKey())
        ->where('beneficiary_role', RewardBeneficiaryRole::Sharer->value)
        ->where('component', RewardComponent::Points->value)
        ->get();

    $dailyCap = RewardCapCounter::query()
        ->where('scope', 'recipient_program_day')
        ->firstOrFail();

    expect($dailyCap->consumed)->toBe(2);

    expect($awards)->toHaveCount(2)
        ->and($awards->sum(fn (RewardAward $award): int => (int) $award->amount_snapshot['points']))->toBe(10)
        ->and($awards->pluck('source_business_key')->all())->not->toContain($oldKey);
});

it('keeps enroll-first share rewards and source claims idempotent after reacceptance', function (): void {
    config(['rewards.outcomes' => array_values(array_unique([
        ...config('rewards.outcomes', []),
        DawahShareOutcomeType::Signup->value,
    ]))]);

    $sharer = User::factory()->create();
    [$program, $version] = seedActivityRewardProgramWithRules('preclaim-reaccept', [
        'eligibility' => [
            'outcomes' => [DawahShareOutcomeType::Signup->value],
            'min_verification' => RewardVerificationLevel::Accepted->value,
            'first_only' => false,
            'exclude_self_credit' => true,
            'require_enrollment' => true,
            'require_claimed_identity' => true,
        ],
        'windows' => ['preclaim_lookback_days' => 30],
        'components' => [[
            'type' => 'points',
            'variants' => [[
                'outcomes' => [DawahShareOutcomeType::Signup->value],
                'roles' => [RewardBeneficiaryRole::Sharer->value],
                'min_verification' => RewardVerificationLevel::Accepted->value,
                'first_only' => true,
                'points' => 3,
                'unit' => 'pts',
            ]],
        ]],
    ], RewardProgramMode::Points);

    HostEnrollRewardProgramMemberAction::run($program, $sharer);

    $visitor = User::factory()->create();
    $share = activityShareRequest($sharer);
    $shareKey = ShareOutcomeBusinessKey::signup((string) $visitor->getKey());
    Queue::fake([EvaluateRewardEntitlementJob::class]);
    app(ShareTrackingService::class)->recordSignup($visitor, $share['request']);

    evaluateScopedRewardJob($shareKey);

    $award = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', $shareKey)
        ->where('recipient_id', (string) $sharer->getKey())
        ->where('beneficiary_role', RewardBeneficiaryRole::Sharer->value)
        ->firstOrFail();

    expect($award->amount_snapshot['points'])->toBe(3)
        ->and(RewardSourceComponentClaim::query()
            ->where('source_business_key', $shareKey)
            ->where('component', RewardComponent::Points->value)
            ->where('beneficiary_role', RewardBeneficiaryRole::Sharer->value)
            ->where('family_key', (string) $program->family_key)
            ->count())->toBe(1);

    $rules = $version->rules;
    $terms = $version->terms;
    $terms['version_label'] = 'v2';
    $terms['material']['changes'] = (string) $terms['material']['changes'].' A new version now applies.';
    $publishedAt = CarbonImmutable::now('UTC')->subSecond();

    app(PublishRewardProgramVersionAction::class)->handle(
        $program,
        $publishedAt,
        $rules,
        $terms,
        'Material terms update',
        $sharer->getMorphClass(),
        (string) $sharer->getKey(),
        $publishedAt,
    );

    Queue::fake([EvaluateRewardEntitlementJob::class]);
    Livewire::actingAs($sharer)
        ->test(RewardMemberIndex::class)
        ->call('reaccept', (string) $program->getKey())
        ->assertSee('Terms reaccepted. Accrued obligations keep their frozen terms.');

    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $shareKey
        && $job->onlyRecipientId === (string) $sharer->getKey()
        && $job->onlyRole === RewardBeneficiaryRole::Sharer
        && $job->onlyFamilyKey === (string) $program->family_key);

    evaluateScopedRewardJob($shareKey, $sharer, RewardBeneficiaryRole::Sharer, $program);

    expect(RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', $shareKey)
        ->where('recipient_id', (string) $sharer->getKey())
        ->where('beneficiary_role', RewardBeneficiaryRole::Sharer->value)
        ->count())->toBe(1)
        ->and($award->refresh()->amount_snapshot['points'])->toBe(3)
        ->and(RewardSourceComponentClaim::query()
            ->where('source_business_key', $shareKey)
            ->where('component', RewardComponent::Points->value)
            ->where('beneficiary_role', RewardBeneficiaryRole::Sharer->value)
            ->where('family_key', (string) $program->family_key)
            ->count())->toBe(1);
});
