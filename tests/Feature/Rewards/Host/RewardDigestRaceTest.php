<?php

declare(strict_types=1);

use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use App\Actions\Rewards\DispatchDueRewardDigestsAction;
use App\Models\RewardDigestSlot;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\ParallelTesting;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardFinanceWorkerDb;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;
use Tests\Support\RealDriverFixture;

function digestRaceReadyDir(string $case): string
{
    $token = ParallelTesting::token();
    $dir = sys_get_temp_dir().'/rewards-digest-race-'.($token !== false ? $token : 'serial').'-'.$case.'-'.getmypid();

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach (glob($dir.'/ready-*') ?: [] as $stale) {
        unlink($stale);
    }

    return $dir;
}

/**
 * @param  string|array{0: string, 1: string}  $modes
 * @param  array<string, mixed>  $payload
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function runDigestRacePair(string $database, string|array $modes, array $payload, string $case): array
{
    $readyDir = digestRaceReadyDir($case);
    file_put_contents($readyDir.'/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));


    $worker = __DIR__.'/RewardDigestRaceWorker.php';

    $make = fn (int $slot): Process => new Process([
        PHP_BINARY, $worker,
        '--database='.$database,
        '--mode='.(is_array($modes) ? $modes[$slot] : $modes),
        '--ready-dir='.$readyDir,
        '--slot='.$slot,
    ], timeout: 120);

    $first = $make(0);
    $second = $make(1);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();

    $decode = static function (Process $process): array {
        $exit = $process->getExitCode();
        $output = trim($process->getOutput());

        if ($output === '') {
            return ['ok' => false, 'exit' => $exit, 'error' => substr(trim($process->getErrorOutput()), 0, 300)];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return ['ok' => false, 'exit' => $exit, 'error' => 'Worker output was not JSON.'];
        }

        $decoded['exit'] = $exit;

        return $decoded;
    };

    return [$decode($first), $decode($second)];
}

/**
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 */
function digestRaceWorkersExited(array $a, array $b): void
{
    Assert::assertSame(0, $a['exit'] ?? null, 'Worker A failed: '.($a['error'] ?? json_encode($a)));
    Assert::assertSame(0, $b['exit'] ?? null, 'Worker B failed: '.($b['error'] ?? json_encode($b)));
}

/**
 * Seeds an active points program, a REAL user member with explicit daily
 * digest preferences, and an Approved award on the worker connection;
 * approval auto-plans the gratitude slot. The genuine approval-time plan
 * (daily, decided 06-16, due 06-17) is already past when the raced
 * dispatch runs at 06-23, so dispatch/cancel races have live material
 * without touching the frozen plan fields. Explicit prefs also sync
 * the real email destination, so races prove both mail and inbox
 * channels.
 *
 * @return array{0: User, 1: RewardDigestSlot}
 */
function digestRaceDueSlot(string $prefix, string $source): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Points);

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $user = User::factory()->create();
    $memberId = (string) $user->getKey();

    app(NotificationSettingsManager::class)->save($user, [
        'settings' => [],
        'triggers' => ['share_gratitude_digest' => ['enabled' => true, 'cadence' => 'daily']],
    ]);

    EnrollRewardMemberAction::run(
        $program->refresh(), 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId));

    // Evaluated and approved at the same instant the raced workers plan
    // from, so the pre-existing awards section shares their ceiling day.
    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program->refresh(), RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $staff = RewardLedgerFixtures::staffId();

    (new TransitionRewardAwardAction(null, $reader))->handle(
        $result->award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'approve',
        null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $slot = RewardDigestSlot::query()
        ->where('recipient_type', 'user')
        ->where('recipient_id', $memberId)
        ->firstOrFail();

    // The raced dispatch/cancel claims need a genuinely due slot. The
    // approval-time plan is immutable, so assert the real planned due
    // date instead of rewriting it.
    Assert::assertTrue(
        $slot->due_at->lte(CarbonImmutable::parse('2026-06-23T08:00:00Z')),
        'Approval-time gratitude plan must be genuinely due before raced dispatch now.'
    );

    return [$user->refresh(), $slot->refresh()];
}

/**
 * Coherence after a raced dispatch/cancel: the approved award still stands,
 * so cancellation can never close the slot — the race settles SENT
 * (dispatch won outright) or PLANNED (cancel won and recalculated with
 * material remaining). Either way nothing sends twice and nothing settled
 * is resurrected; a PLANNED slot redrives to exactly-once SENT.
 */
function digestRaceAssertDispatchCancelCoherent(string $memberId): void
{
    $slot = RewardDigestSlot::query()
        ->where('recipient_type', 'user')
        ->where('recipient_id', $memberId)
        ->firstOrFail();

    $commIds = Communication::query()
        ->where('idempotency_key', (string) $slot->plan_key)
        ->pluck('id')
        ->all();

    $planKey = (string) $slot->plan_key;

    $sentCount = static function () use ($planKey): int {
        $ids = Communication::query()->where('idempotency_key', $planKey)->pluck('id')->all();

        return $ids === []
            ? 0
            : CommunicationDelivery::query()
                ->whereIn('communication_id', $ids)
                ->whereIn('status', [
                    DeliveryStatus::Sent, DeliveryStatus::Received, DeliveryStatus::Delivered,
                    DeliveryStatus::Opened, DeliveryStatus::Read, DeliveryStatus::Clicked,
                    DeliveryStatus::Replied,
                ])
                ->count();
    };

    $inboxCount = NotificationInbox::query()->where('recipient_id', $memberId)->count();

    // A single claim wins, so at most one transport exists; the approved
    // award keeps cancellation from ever closing the slot.
    expect(count($commIds))->toBeLessThanOrEqual(1)
        ->and($slot->status)->not->toBe(RewardDigestSlot::STATUS_CANCELLED);

    if ($slot->status === RewardDigestSlot::STATUS_SENT) {
        expect($sentCount())->toBe(2)
            ->and($inboxCount)->toBe(1);

        return;
    }

    expect($slot->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($sentCount())->toBe(0)
        ->and($inboxCount)->toBe(0);

    // The pending slot redrives to exactly one delivery per channel.
    DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T09:00:00Z'));

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
        ->and($sentCount())->toBe(2)
        ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(1);
}

$digestLabel = 'PostgreSQL 17';
    it("sends a due digest exactly once under a raced double dispatch on {$digestLabel}", function (): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp('dgclaim');

        try {
            [$user] = digestRaceDueSlot('dg-claim', 'dg-claim-source-1');

            [$first, $second] = runDigestRacePair($database, 'dispatch', [
                'now' => '2026-06-23T08:00:00Z',
            ], 'dgclaim');

            digestRaceWorkersExited($first, $second);

            $dispatched = (int) ($first['outcome']['dispatched'] ?? 0) + (int) ($second['outcome']['dispatched'] ?? 0);
            $reconciled = (int) ($first['outcome']['reconciled'] ?? 0) + (int) ($second['outcome']['reconciled'] ?? 0);
            $cancelled = (int) ($first['outcome']['cancelled'] ?? 0) + (int) ($second['outcome']['cancelled'] ?? 0);
            $skipped = (int) ($first['outcome']['skipped'] ?? 0) + (int) ($second['outcome']['skipped'] ?? 0);

            // One claim wins; its sync transport sends inline, so exactly
            // one reconcile settles the slot. The loser always skips the
            // lost claim; its reconcile scan may or may not observe the
            // slot mid-send, so skipped is 1 or 2 — never 0, never 3.
            expect($dispatched)->toBe(1)
                ->and($reconciled)->toBe(1)
                ->and($cancelled)->toBe(0)
                ->and($skipped)->toBeGreaterThanOrEqual(1)
                ->and($skipped)->toBeLessThanOrEqual(2);

            $slot = RewardDigestSlot::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', (string) $user->getKey())
                ->firstOrFail();

            expect($slot->status)->toBe(RewardDigestSlot::STATUS_SENT)
                ->and($slot->communication_id)->not->toBeNull()
                ->and(Communication::query()->whereKey((string) $slot->communication_id)->count())->toBe(1)
                ->and(NotificationInbox::query()->where('recipient_id', (string) $user->getKey())->count())->toBe(1);
        } finally {
            RewardFinanceWorkerDb::tearDown($database, $previous);
        }
    });

    it("converges concurrent same-category plans into one slot on {$digestLabel}", function (): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp('dgplan');

        try {
            [$user] = digestRaceDueSlot('dg-plan', 'dg-plan-source-1');

            // Both workers re-plan the same won day from scratch: the
            // insert race must merge, never duplicate.
            RewardDigestSlot::query()->delete();

            $decided = '2026-06-16T00:00:00Z';

            [$first, $second] = runDigestRacePair($database, 'plan', [
                'member_id' => (string) $user->getKey(),
                'categories' => ['awards'],
                'decided_at' => $decided,
            ], 'dgplan');

            digestRaceWorkersExited($first, $second);

            expect(RewardDigestSlot::query()->count())->toBe(1)
                ->and($first['slot_id'] ?? null)->toBe($second['slot_id'] ?? null);

            $slot = RewardDigestSlot::query()->firstOrFail();

            expect($slot->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
                ->and(array_values((array) $slot->categories))->toBe(['awards']);
        } finally {
            RewardFinanceWorkerDb::tearDown($database, $previous);
        }
    });

    it("merges concurrent cross-category plans into one ceiling slot on {$digestLabel}", function (): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp('dgmerge');

        try {
            [$user] = digestRaceDueSlot('dg-merge', 'dg-merge-source-1');

            $decided = '2026-06-16T00:00:00Z';

            [$first, $second] = runDigestRacePair($database, 'plan', [
                'slots' => [
                    ['member_id' => (string) $user->getKey(), 'categories' => ['badges'], 'decided_at' => $decided],
                    ['member_id' => (string) $user->getKey(), 'categories' => ['milestones'], 'decided_at' => $decided],
                ],
            ], 'dgmerge');

            digestRaceWorkersExited($first, $second);

            expect(RewardDigestSlot::query()->count())->toBe(1);

            $slot = RewardDigestSlot::query()->firstOrFail();
            $categories = array_values((array) $slot->categories);
            sort($categories);

            expect($categories)->toBe(['awards', 'badges', 'milestones']);
        } finally {
            RewardFinanceWorkerDb::tearDown($database, $previous);
        }
    });

    it("keeps a raced dispatch and cancel coherent on {$digestLabel}", function (): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp('dgcxl');

        try {
            [$user] = digestRaceDueSlot('dg-cxl', 'dg-cxl-source-1');
            $memberId = (string) $user->getKey();

            [$dispatch, $cancel] = runDigestRacePair($database, ['dispatch', 'cancel'], [
                'slots' => [
                    ['now' => '2026-06-23T08:00:00Z'],
                    ['member_id' => $memberId, 'reason' => 'member-opt-out-race', 'decided_at' => '2026-06-23T08:00:00Z'],
                ],
            ], 'dgcxl');

            digestRaceWorkersExited($dispatch, $cancel);

            digestRaceAssertDispatchCancelCoherent($memberId);
        } finally {
            RewardFinanceWorkerDb::tearDown($database, $previous);
        }
    });

    it("keeps a raced cancel and dispatch coherent on {$digestLabel}", function (): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp('dgxcl');

        try {
            [$user] = digestRaceDueSlot('dg-xcl', 'dg-xcl-source-1');
            $memberId = (string) $user->getKey();

            [$cancel, $dispatch] = runDigestRacePair($database, ['cancel', 'dispatch'], [
                'slots' => [
                    ['member_id' => $memberId, 'reason' => 'member-opt-out-race', 'decided_at' => '2026-06-23T08:00:00Z'],
                    ['now' => '2026-06-23T08:00:00Z'],
                ],
            ], 'dgxcl');

            digestRaceWorkersExited($cancel, $dispatch);

            digestRaceAssertDispatchCancelCoherent($memberId);
        } finally {
            RewardFinanceWorkerDb::tearDown($database, $previous);
        }
    });
