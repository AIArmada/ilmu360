<?php

declare(strict_types=1);

// R7 proof: gratitude planning fires from committed award/badge/milestone
// changes, the slot is the shared per-day ceiling across categories and
// channels, and the slot lifecycle reflects observed delivery — planned
// → sending → sent only on a real send, with cancel/recalculate,
// failure requeue, and outage recovery. Concurrency closure lives in
// RewardFinanceRaceTest (plan-merge, dispatch-claim, cancel races).

use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Jobs\DispatchManagedNotificationJob;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Communications\Services\CommunicationRecorderService;
use App\Actions\Rewards\CancelRewardDigestAction;
use App\Actions\Rewards\DispatchDueRewardDigestsAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\GrantRewardBadgeAction;
use App\Actions\Rewards\PlanRewardGratitudeDigestAction;
use App\Actions\Rewards\RecordRewardMilestoneContributionAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardAward;
use App\Models\RewardDigestSlot;
use App\Models\User;
use App\Notifications\Channels\DigestMailChannel;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\ShareGratitudeDigestNotification;
use App\Services\Notifications\NotificationSettingsManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

function digestUser(array $settings = [], array $triggers = []): User
{
    $user = User::factory()->create();

    // Daily unless the test says otherwise (the catalog default is
    // weekly); the trigger stays enabled unless disabled explicitly.
    $triggers['share_gratitude_digest'] = array_merge(
        ['enabled' => true, 'cadence' => 'daily'],
        $triggers['share_gratitude_digest'] ?? [],
    );

    app(NotificationSettingsManager::class)->save($user, [
        'settings' => $settings,
        'triggers' => $triggers,
    ]);

    return $user;
}

function digestProgram(string $prefix, User $user, RewardProgramMode $mode = RewardProgramMode::Points): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, $mode);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    EnrollRewardMemberAction::run(
        $program, 'user', (string) $user->getKey(), $version, 'member-portal',
        CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    return [$program, $version];
}

function digestApproveAward($program, User $user, string $source, string $at): array
{
    $memberId = (string) $user->getKey();
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId));

    (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse($at),
    );

    $award = RewardAward::query()
        ->where('source_business_key', $source)
        ->orderByDesc('created_at')
        ->firstOrFail();

    $approved = (new TransitionRewardAwardAction(null, $reader))->handle(
        $award, RewardAwardStatus::Approved, 'user', RewardLedgerFixtures::staffId(), 'staff',
        'verified-ok', null, null, CarbonImmutable::parse($at),
    );

    return [$approved->refresh(), $reader];
}

function digestSlotFor(User $user): RewardDigestSlot
{
    return RewardDigestSlot::query()
        ->where('recipient_id', (string) $user->getKey())
        ->orderByDesc('created_at')
        ->firstOrFail();
}

/**
 * Drain the slot's queued transport through the REAL job class — what
 * the recovered queue runs. Under RefreshDatabase the after-commit job
 * never fires inline, so tests drive it explicitly; worker-DB races
 * commit for real and the job runs itself.
 */
function digestDrain(RewardDigestSlot $slot): void
{
    $slot->refresh();

    $user = User::query()->whereKey((string) $slot->recipient_id)->firstOrFail();
    $payload = $slot->payload ?? [];

    $deliveries = CommunicationDelivery::query()
        ->where('communication_id', (string) $slot->communication_id)
        ->orderBy('id')
        ->get();

    $byChannel = [];

    foreach ($deliveries as $delivery) {
        $byChannel[(string) $delivery->channel] = (string) $delivery->getKey();
    }

    $notification = (new ShareGratitudeDigestNotification([
        'text' => (string) ($payload['text'] ?? ''),
        'counts' => (array) ($payload['counts'] ?? []),
        'sections' => (array) ($payload['sections'] ?? []),
        'window' => (array) ($payload['window'] ?? []),
        'plan_key' => (string) $slot->plan_key,
        'slot_id' => (string) $slot->getKey(),
    ]))->withCommunicationContext((string) $slot->communication_id, $byChannel);

    DispatchManagedNotificationJob::dispatchSync(
        notifiable: $user,
        notification: $notification,
        channels: array_keys($byChannel),
    );
}

function digestMailCount(): int
{
    $transport = Mail::mailer()->getSymfonyTransport();

    return method_exists($transport, 'messages') ? count($transport->messages()) : 0;
}

function digestFailAll(RewardDigestSlot $slot, string $reason = 'provider-down'): void
{
    $slot->refresh();

    $deliveries = CommunicationDelivery::query()
        ->where('communication_id', (string) $slot->communication_id)
        ->orderBy('id')
        ->get();

    // Simulates the provider-failure recording through the real
    // recorder contract (what failed jobs and provider webhooks write).
    foreach ($deliveries as $delivery) {
        app(CommunicationRecorderService::class)->markFailed(
            (string) $slot->communication_id, (string) $delivery->getKey(), $reason,
        );
    }
}

it('plans a delayed slot from an approved award with real nonzero counts', function () {
    $user = digestUser();
    [$program] = digestProgram('r7-plan', $user);

    [$award] = digestApproveAward($program, $user, 'src-r7-plan-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);

    expect($slot->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($slot->categories)->toBe(['awards'])
        ->and($slot->payload['total'])->toBe(1)
        ->and($slot->payload['counts'])->toBe(['points' => 1])
        ->and($slot->payload['text'])->toContain('1 recent award')
        ->and($slot->due_at->greaterThan(CarbonImmutable::parse('2026-06-15T10:00:00Z')->addHours(20)))->toBeTrue()
        ->and($award->status)->toBe(RewardAwardStatus::Approved);
});

it('merges award, badge, and milestone events into one daily slot', function () {
    $user = digestUser();
    [$program] = digestProgram('r7-merge', $user);
    $memberId = (string) $user->getKey();
    $staff = RewardLedgerFixtures::staffId();

    digestApproveAward($program, $user, 'src-r7-merge-1', '2026-06-15T10:00:00Z');

    GrantRewardBadgeAction::run(
        'user', $memberId, $program->family_key, 'first-share', (string) $program->getKey(), null,
        'user', $staff, 'staff', 'earned', null, CarbonImmutable::parse('2026-06-15T11:00:00Z'),
    );

    $slots = RewardDigestSlot::query()->where('recipient_id', $memberId)->get();

    expect($slots)->toHaveCount(1)
        ->and($slots->first()->categories)->toBe(['awards', 'badges'])
        ->and($slots->first()->payload['total'])->toBe(2)
        ->and($slots->first()->payload['sections']['badges']['total'])->toBe(1)
        ->and($slots->first()->payload['text'])->toContain('1 recent badge');
});

it('plans a milestone section when a cycle achieves', function () {
    $user = digestUser();
    $memberId = (string) $user->getKey();

    $milestones = [['key' => 'three-checkins', 'target_count' => 2, 'window_days' => 30, 'reward' => ['type' => 'points', 'points' => 5, 'unit' => 'pts']]];
    [$program] = RewardLedgerFixtures::seedVersionedProgram('r7-mile', RewardProgramMode::Points, ['milestones' => $milestones]);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $version = $program->versions()->orderByDesc('version')->firstOrFail();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;

    foreach (['src-r7-mile-1' => '2026-06-12T00:00:00Z', 'src-r7-mile-2' => '2026-06-13T00:00:00Z'] as $source => $occurred) {
        $reader->seed(RewardLedgerFixtures::fact($source, $memberId, $occurred));

        $result = (new RecordRewardMilestoneContributionAction($reader))->handle(
            'user', $memberId, $program->family_key, 'three-checkins', 2, 30,
            RewardComponent::Points, ['type' => 'points', 'points' => 5, 'unit' => 'pts'],
            $source, 'user', $memberId, RewardBeneficiaryRole::Participant,
            RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse($occurred),
            (string) $program->getKey(),
        );
    }

    expect($result['achieved'])->toBeTrue();

    $slot = digestSlotFor($user);

    expect($slot->categories)->toContain('milestones')
        ->and($slot->payload['sections']['milestones']['total'])->toBe(1);
});

it('refuses unknown digest categories instead of storing them cosmetically', function () {
    $user = digestUser();

    expect(fn () => PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['gratitude', 'weekly'], CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Unknown digest category');

    expect(RewardDigestSlot::query()->count())->toBe(0);
});

it('leaves no slot for non-user and opted-out recipients without failing awards', function () {
    $stranger = (string) Str::uuid();

    [$program] = RewardLedgerFixtures::seedVersionedProgram('r7-optout', RewardProgramMode::Points);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $version = $program->versions()->orderByDesc('version')->firstOrFail();

    EnrollRewardMemberAction::run(
        $program, 'user', $stranger, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-r7-optout-1', $stranger));

    (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $stranger, RewardBeneficiaryRole::Recipient,
        'src-r7-optout-1', CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    );

    $award = RewardAward::query()->where('source_business_key', 'src-r7-optout-1')->firstOrFail();

    // No User row: the award approves, planning yields no slot.
    $approved = (new TransitionRewardAwardAction(null, $reader))->handle(
        $award, RewardAwardStatus::Approved, 'user', RewardLedgerFixtures::staffId(), 'staff',
        'verified-ok', null, null, CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    );

    expect($approved->status)->toBe(RewardAwardStatus::Approved)
        ->and(RewardDigestSlot::query()->count())->toBe(0);

    // Opted-out user: same — award stands, no slot.
    $user = digestUser([], ['share_gratitude_digest' => ['enabled' => false]]);
    [$program2] = digestProgram('r7-optout2', $user);

    [$award2] = digestApproveAward($program2, $user, 'src-r7-optout-2', '2026-06-15T10:00:00Z');

    expect($award2->status)->toBe(RewardAwardStatus::Approved)
        ->and(RewardDigestSlot::query()->where('recipient_id', (string) $user->getKey())->count())->toBe(0);
});

it('sends once across mail and inbox and reconciles to sent with channel outcomes', function () {
    $realQueue = Queue::getFacadeRoot();
    Queue::fake();

    $user = digestUser();
    [$program] = digestProgram('r7-send', $user);

    digestApproveAward($program, $user, 'src-r7-send-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);

    $outcome = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($outcome['dispatched'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
        ->and($slot->communication_id)->not->toBeNull()
        ->and(digestMailCount())->toBe(0);

    $deliveries = CommunicationDelivery::query()
        ->where('communication_id', (string) $slot->communication_id)
        ->orderBy('id')
        ->get();

    expect($deliveries->pluck('channel')->all())->toBe([DigestMailChannel::class, InboxChannel::class])
        ->and($deliveries->pluck('status')->all())->toBe([DeliveryStatus::Queued, DeliveryStatus::Queued]);

    // A second dispatch before delivery neither duplicates nor releases.
    $again = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinutes(2));

    expect($again['dispatched'])->toBe(0)
        ->and(Communication::query()->where('idempotency_key', (string) $slot->plan_key)->count())->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);

    // The held transport drains through the real job exactly once.
    Queue::swap($realQueue);
    digestDrain($slot);

    expect(digestMailCount())->toBe(1)
        ->and(NotificationInbox::query()->where('recipient_id', (string) $user->getKey())->count())->toBe(1);

    $reconciled = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinutes(3));

    expect($reconciled['reconciled'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
        ->and($slot->sent_at)->not->toBeNull()
        ->and($slot->payload['delivery']['channels'][DigestMailChannel::class])->toBe(DeliveryStatus::Sent->value)
        ->and($slot->payload['delivery']['channels'][InboxChannel::class])->toBe(DeliveryStatus::Sent->value);

    // Settled slots never resend.
    $settled = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinutes(4));

    expect($settled['dispatched'])->toBe(0)
        ->and($settled['reconciled'])->toBe(0)
        ->and(Communication::query()->where('idempotency_key', (string) $slot->plan_key)->count())->toBe(1)
        ->and(digestMailCount())->toBe(1);
});

it('recovers a queued outage exactly once when the transport drains', function () {
    $realQueue = Queue::getFacadeRoot();
    Queue::fake();

    $user = digestUser();
    [$program] = digestProgram('r7-outage', $user);

    digestApproveAward($program, $user, 'src-r7-outage-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);

    $outcome = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($outcome['dispatched'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);

    Queue::assertPushed(DispatchManagedNotificationJob::class, 1);

    // The queue drains later: one send, one sent mark.
    Queue::swap($realQueue);
    digestDrain($slot);

    $reconciled = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinutes(2));

    expect($reconciled['reconciled'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
        ->and(digestMailCount())->toBe(1)
        ->and(Communication::query()->where('idempotency_key', (string) $slot->plan_key)->count())->toBe(1);
});

it('cancels planned material before send with no transport', function () {
    $user = digestUser();
    [$program] = digestProgram('r7-cancel', $user);
    $staff = RewardLedgerFixtures::staffId();

    digestApproveAward($program, $user, 'src-r7-cancel-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);

    $cancelled = CancelRewardDigestAction::run(
        $slot, 'member-asked-to-stop', 'user', $staff, 'staff', true, CarbonImmutable::parse('2026-06-15T11:00:00Z'),
    );

    expect($cancelled->status)->toBe(RewardDigestSlot::STATUS_CANCELLED)
        ->and($cancelled->payload['cancellation']['reason'])->toBe('member-asked-to-stop');

    $outcome = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($outcome['dispatched'])->toBe(0)
        ->and($outcome['cancelled'])->toBe(0)
        ->and(Communication::query()->where('idempotency_key', (string) $slot->plan_key)->count())->toBe(0)
        ->and(digestMailCount())->toBe(0);
});

it('cancels planned slots on reversal when nothing remains, recalculates otherwise', function () {
    $user = digestUser();
    [$program] = digestProgram('r7-rev', $user);
    $staff = RewardLedgerFixtures::staffId();

    [$first, $reader] = digestApproveAward($program, $user, 'src-r7-rev-1', '2026-06-15T10:00:00Z');
    [$second] = digestApproveAward($program, $user, 'src-r7-rev-2', '2026-06-15T11:00:00Z');

    $slot = digestSlotFor($user);

    expect($slot->payload['total'])->toBe(2);

    // One of two reverses: the slot recalculates and stays planned.
    (new TransitionRewardAwardAction(null, $reader))->handle(
        $first, RewardAwardStatus::Reversed, 'user', $staff, 'staff',
        'source-invalid', null, null, CarbonImmutable::parse('2026-06-15T12:00:00Z'),
    );

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($slot->payload['total'])->toBe(1);

    // The last one reverses: nothing left, the slot cancels.
    (new TransitionRewardAwardAction(null, $reader))->handle(
        $second, RewardAwardStatus::Reversed, 'user', $staff, 'staff',
        'source-invalid', null, null, CarbonImmutable::parse('2026-06-15T13:00:00Z'),
    );

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_CANCELLED)
        ->and($slot->payload['cancellation']['reason'])->toBe('award-reversed')
        ->and(Communication::query()->where('idempotency_key', (string) $slot->plan_key)->count())->toBe(0);
});

it('cancels queued transport on correction and redelivers recalculated material', function () {
    $realQueue = Queue::getFacadeRoot();
    Queue::fake();

    $user = digestUser();
    [$program] = digestProgram('r7-sndcancel', $user);
    $staff = RewardLedgerFixtures::staffId();

    [$first, $reader] = digestApproveAward($program, $user, 'src-r7-sndcancel-1', '2026-06-15T10:00:00Z');
    digestApproveAward($program, $user, 'src-r7-sndcancel-2', '2026-06-15T11:00:00Z');

    $slot = digestSlotFor($user);

    DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);

    $firstComm = (string) $slot->communication_id;

    // Correction while queued: the transport cancels, material
    // recalculates, the slot requeues for redelivery.
    (new TransitionRewardAwardAction(null, $reader))->handle(
        $first, RewardAwardStatus::Reversed, 'user', $staff, 'staff',
        'source-invalid', null, null, CarbonImmutable::parse('2026-06-15T12:00:00Z'),
    );

    $slot->refresh();

    expect($slot->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($slot->payload['total'])->toBe(1)
        ->and(Communication::query()->whereKey($firstComm)->firstOrFail()->status)
        ->toBe(CommunicationStatus::Cancelled);

    // Redelivery sends once with the recalculated payload.
    Queue::swap($realQueue);

    $redelivered = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinutes(5));

    expect($redelivered['dispatched'])->toBe(1)
        ->and($redelivered['reconciled'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
        ->and((string) $slot->communication_id)->not->toBe($firstComm)
        ->and($slot->payload['total'])->toBe(1)
        ->and(digestMailCount())->toBe(1);
});

it('skips a late job on cancelled transport without recording a phantom send', function () {
    $realQueue = Queue::getFacadeRoot();
    Queue::fake();

    $user = digestUser();
    [$program] = digestProgram('r7-zombie', $user);
    $staff = RewardLedgerFixtures::staffId();

    digestApproveAward($program, $user, 'src-r7-zombie-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);

    DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);

    $firstComm = (string) $slot->communication_id;

    // Correction wins while the send job is still held: the transport
    // cancels and the slot requeues with material remaining.
    CancelRewardDigestAction::run(
        $slot->refresh(), 'award-recheck', 'user', $staff, 'staff', false, CarbonImmutable::parse('2026-06-15T12:00:00Z'),
    );

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_PLANNED);

    // The late job finally runs against the cancelled transport: both
    // channels skip, nothing sends, nothing is recorded as sent.
    Queue::swap($realQueue);
    digestDrain($slot->refresh());

    expect(CommunicationDelivery::query()->where('communication_id', $firstComm)->orderBy('id')->pluck('status')->all())
        ->toBe([DeliveryStatus::Cancelled, DeliveryStatus::Cancelled])
        ->and(digestMailCount())->toBe(0)
        ->and(NotificationInbox::query()->where('recipient_id', (string) $user->getKey())->count())->toBe(0)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_PLANNED);

    // Redelivery still converges to exactly one send per channel.
    $redelivered = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinutes(5));

    expect($redelivered['reconciled'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
        ->and(digestMailCount())->toBe(1)
        ->and(NotificationInbox::query()->where('recipient_id', (string) $user->getKey())->count())->toBe(1);
});

it('refuses cancellation after delivery and during ambiguous flight', function () {
    Queue::fake();

    $user = digestUser();
    [$program] = digestProgram('r7-refuse', $user);
    $staff = RewardLedgerFixtures::staffId();

    digestApproveAward($program, $user, 'src-r7-refuse-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);

    DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    // Ambiguous in-flight work: cancellation refuses, never releases.
    $slot->refresh();

    $deliveries = CommunicationDelivery::query()
        ->where('communication_id', (string) $slot->communication_id)
        ->orderBy('id')
        ->get();

    app(CommunicationRecorderService::class)->markSending(
        (string) $slot->communication_id, (string) $deliveries->firstWhere('channel', DigestMailChannel::class)->getKey(),
    );

    expect(fn () => CancelRewardDigestAction::run(
        $slot->refresh(), 'too-late', 'user', $staff, 'staff', true, CarbonImmutable::parse('2026-06-15T12:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'in flight');

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);

    // Late completion resolves the ambiguity: the slot sends.
    app(CommunicationRecorderService::class)->markSent(
        (string) $slot->communication_id,
        (string) $deliveries->firstWhere('channel', DigestMailChannel::class)->getKey(),
        ['channel' => DigestMailChannel::class],
    );

    DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinutes(5));

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT);

    expect(fn () => CancelRewardDigestAction::run(
        $slot->refresh(), 'too-late', 'user', $staff, 'staff', true, CarbonImmutable::parse('2026-06-15T12:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'already sent');
});

it('requeues failed sends bounded, then cancels as delivery-failed', function () {
    Queue::fake();

    $user = digestUser();
    [$program] = digestProgram('r7-fail', $user);

    digestApproveAward($program, $user, 'src-r7-fail-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);
    $due = $slot->due_at->addMinute();

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        DispatchDueRewardDigestsAction::run(50, $due->addMinutes($attempt));

        expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);

        digestFailAll($slot);

        DispatchDueRewardDigestsAction::run(50, $due->addMinutes($attempt));

        expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
            ->and($slot->payload['delivery']['attempts'])->toBe($attempt);
    }

    DispatchDueRewardDigestsAction::run(50, $due->addMinutes(10));

    digestFailAll($slot);

    DispatchDueRewardDigestsAction::run(50, $due->addMinutes(10));

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_CANCELLED)
        ->and($slot->payload['cancellation']['reason'])->toBe('delivery-failed')
        ->and(Communication::query()->where('idempotency_key', (string) $slot->plan_key)->count())->toBe(3)
        ->and(digestMailCount())->toBe(0);
});

it('holds sends during quiet hours and releases after', function () {
    $user = digestUser(['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00', 'timezone' => 'UTC']);
    [$program] = digestProgram('r7-quiet', $user);

    digestApproveAward($program, $user, 'src-r7-quiet-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);

    // Due inside quiet hours: skipped, still planned.
    $quietDue = CarbonImmutable::parse($slot->due_at->toDateString().'T23:00:00Z');
    $outcome = DispatchDueRewardDigestsAction::run(50, $quietDue);

    expect($outcome['skipped'])->toBeGreaterThanOrEqual(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($slot->communication_id)->toBeNull();

    // After quiet hours: sending proceeds end to end.
    $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse($slot->due_at->toDateString().'T08:00:00Z')->addDay());

    expect($outcome['dispatched'])->toBe(1)
        ->and($outcome['reconciled'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
        ->and(digestMailCount())->toBe(1);
});

it('cancels instead of sending when the window holds nothing', function () {
    $user = digestUser();

    $slot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['awards', 'badges', 'milestones'],
        CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    );

    expect($slot->payload['total'])->toBe(0)
        ->and(array_keys($slot->payload['sections']))->toBe(['awards', 'badges', 'milestones']);

    $outcome = DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($outcome['cancelled'])->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_CANCELLED)
        ->and(Communication::query()->where('idempotency_key', (string) $slot->plan_key)->count())->toBe(0);
});

it('buckets the ceiling day in the recipient timezone', function () {
    $user = digestUser(['timezone' => 'Asia/Kuala_Lumpur']);

    // 2026-06-16 00:00 +08:00: tomorrow at 08:00 KL is 2026-06-17.
    $slot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['awards'], CarbonImmutable::parse('2026-06-15T16:00:00Z'),
    );

    expect($slot->local_day)->toBe('2026-06-17')
        ->and($slot->due_at->toIso8601String())->toBe('2026-06-17T00:00:00+00:00');

    // A UTC recipient planning the same instant lands a different day.
    $utc = digestUser(['timezone' => 'UTC']);

    $utcSlot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $utc->getKey(), ['awards'], CarbonImmutable::parse('2026-06-15T16:00:00Z'),
    );

    expect($utcSlot->local_day)->toBe('2026-06-16');
});

it('plans weekly cadence for the next weekly day', function () {
    $user = digestUser(
        ['timezone' => 'UTC', 'digest_delivery_time' => '09:00', 'digest_weekly_day' => 1],
        ['share_gratitude_digest' => ['enabled' => true, 'cadence' => 'weekly']],
    );

    // Friday 2026-06-19: next Monday is 2026-06-22.
    $slot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['awards'], CarbonImmutable::parse('2026-06-19T10:00:00Z'),
    );

    expect($slot->local_day)->toBe('2026-06-22')
        ->and($slot->due_at->toIso8601String())->toBe('2026-06-22T09:00:00+00:00');
});

it('leaves sent and cancelled days untouched on replan', function () {
    $user = digestUser();
    [$program] = digestProgram('r7-term', $user);

    digestApproveAward($program, $user, 'src-r7-term-1', '2026-06-15T10:00:00Z');

    $slot = digestSlotFor($user);
    $day = $slot->local_day;

    DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT);

    // Replanning the same local day after send: untouched, no rewrite.
    $replay = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['badges'], CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    );

    expect((string) $replay->getKey())->toBe((string) $slot->getKey())
        ->and($replay->local_day)->toBe($day)
        ->and($replay->categories)->toBe(['awards'])
        ->and($replay->status)->toBe(RewardDigestSlot::STATUS_SENT);
});
