<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Rewards\Actions\ApplyRewardSourceCorrectionsAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use App\Enums\DawahShareOutcomeType;
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
