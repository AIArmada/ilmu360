<?php

declare(strict_types=1);

use AIArmada\Communications\Contracts\CommunicationRecorder;
use AIArmada\Communications\Data\CommunicationContextData;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationAttempt;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Communications\Services\CommunicationRecorderService;
use App\Actions\Rewards\CancelRewardDigestAction;
use App\Actions\Rewards\DispatchDueRewardDigestsAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\ResolveDigestMailAmbiguityAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardDigestSlot;
use App\Models\User;
use App\Notifications\Channels\DigestMailChannel;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\ShareGratitudeDigestNotification;
use App\Services\Notifications\NotificationSettingsManager;
use App\Support\Communications\GuardedDeliverySend;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Feature\Rewards\Host\Support\RewardFinanceWorkerDb;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

final class DigestMailThrowOnceRecorder implements CommunicationRecorder
{
    private bool $thrown = false;

    public function __construct(
        private readonly CommunicationRecorderService $inner,
    ) {}

    public function createCommunication(CommunicationContextData $context): Communication
    {
        return $this->inner->createCommunication($context);
    }

    public function markSending(string $communicationId, string $deliveryId): void
    {
        $this->inner->markSending($communicationId, $deliveryId);
    }

    public function markSent(string $communicationId, string $deliveryId, array $result): void
    {
        if (! $this->thrown) {
            $this->thrown = true;

            throw new RuntimeException('simulated recording crash after provider acceptance');
        }

        $this->inner->markSent($communicationId, $deliveryId, $result);
    }

    public function markFailed(string $communicationId, string $deliveryId, string $failureMessage): void
    {
        $this->inner->markFailed($communicationId, $deliveryId, $failureMessage);
    }

    public function cancelCommunication(string $communicationId): void
    {
        $this->inner->cancelCommunication($communicationId);
    }
}

final class DigestMailAcceptThenThrowTransport implements TransportInterface
{
    public function __construct(
        private readonly string $observer,
        private readonly ArrayTransport $inner,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        file_put_contents($this->observer, "accepted\n", FILE_APPEND);

        throw new TransportException('simulated transport timeout after provider acceptance');
    }

    public function messages(): Collection
    {
        return $this->inner->messages();
    }

    public function __toString(): string
    {
        return 'accept-then-throw';
    }
}

final class DigestMailLogicThrowTransport implements TransportInterface
{
    public function __construct(
        private readonly ArrayTransport $inner,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new InvalidArgumentException('simulated logic failure inside transport: invalid recipient');
    }

    public function messages(): Collection
    {
        return $this->inner->messages();
    }

    public function __toString(): string
    {
        return 'logic-throw';
    }
}

final class DigestMailAcceptThenThrowLogicTransport implements TransportInterface
{
    public function __construct(
        private readonly string $observer,
        private readonly ArrayTransport $inner,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        file_put_contents($this->observer, "accepted\n", FILE_APPEND);

        throw new LogicException('simulated logic failure after provider acceptance');
    }

    public function messages(): Collection
    {
        return $this->inner->messages();
    }

    public function __toString(): string
    {
        return 'accept-then-throw-logic';
    }
}

function mailAmbiguityObserverFile(string $driver): string
{
    return sys_get_temp_dir().'/digest-mail-amb-'.$driver.'-'.getmypid().'.log';
}

function mailAmbiguityMailCount(): int
{
    $transport = Mail::mailer()->getSymfonyTransport();

    return method_exists($transport, 'messages') ? count($transport->messages()) : 0;
}

function mailAmbiguityFileCount(string $file): int
{
    if (! is_file($file)) {
        return 0;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return is_array($lines) ? count($lines) : 0;
}

function mailAmbiguityDueSlot(string $prefix, string $source): array
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

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program->refresh(), RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    (new TransitionRewardAwardAction(null, $reader))->handle(
        $result->award, RewardAwardStatus::Approved, 'user', RewardLedgerFixtures::staffId(), 'staff', 'approve',
        null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $slot = RewardDigestSlot::query()
        ->where('recipient_type', 'user')
        ->where('recipient_id', $memberId)
        ->firstOrFail();

    return [$user->refresh(), $slot->refresh()];
}

function mailAmbiguityDrain(RewardDigestSlot $slot): void
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

    // One job attempt through the real mail/attempt/communication
    // boundary (pre-send flip, guarded channels, post-send record).
    // Queue-level failure accounting stays out: tries remain, so a
    // crash here models the attempt a worker would retry.
    Notification::sendNow($user, $notification, array_keys($byChannel));
}

foreach (['pgsql' => 'PostgreSQL17', 'mariadb' => 'MariaDB11.4'] as $mailDriver => $mailLabel) {
    it("never resends mail after acceptance plus recording crash on {$mailLabel}", function () use ($mailDriver): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp($mailDriver, 'mailamb');

        $observer = mailAmbiguityObserverFile($mailDriver);

        if (is_file($observer)) {
            unlink($observer);
        }

        Event::listen(MessageSent::class, function () use ($observer): void {
            file_put_contents($observer, "sent\n", FILE_APPEND);
        });

        Queue::fake();

        try {
            [$user] = mailAmbiguityDueSlot('mail-amb', 'mail-amb-source-1');
            $memberId = (string) $user->getKey();

            $slot = RewardDigestSlot::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', $memberId)
                ->firstOrFail();

            $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:00:00Z'));

            expect($outcome['dispatched'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(mailAmbiguityMailCount())->toBe(0)
                ->and(mailAmbiguityFileCount($observer))->toBe(0);

            $communicationId = (string) $slot->communication_id;
            $mailDeliveryId = (string) CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->where('channel', DigestMailChannel::class)
                ->value('id');

            app()->bind(CommunicationRecorder::class, fn (): DigestMailThrowOnceRecorder => new DigestMailThrowOnceRecorder(
                app(CommunicationRecorderService::class),
            ));

            try {
                mailAmbiguityDrain($slot->refresh());
                $crashed = false;
            } catch (RuntimeException $exception) {
                $crashed = $exception->getMessage() === 'simulated recording crash after provider acceptance';
            }

            expect($crashed)->toBeTrue()
                ->and(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $mailDelivery = CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail();

            expect($mailDelivery->status)->toBe(DeliveryStatus::Sending)
                ->and(CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->count())->toBe(1)
                ->and(CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->whereNull('responded_at')->whereNull('failed_at')->count())->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(0);

            expect(fn () => CancelRewardDigestAction::run(
                $slot->refresh(), 'too-late', 'user', RewardLedgerFixtures::staffId(), 'staff', true,
                CarbonImmutable::parse('2026-06-23T08:05:00Z'),
            ))->toThrow(InvalidArgumentException::class, 'in flight');

            app()->bind(CommunicationRecorder::class, CommunicationRecorderService::class);

            $resolved = ResolveDigestMailAmbiguityAction::run(
                $mailDeliveryId, 'sent', 'user', RewardLedgerFixtures::staffId(), 'staff', 'test-observed-1',
            );

            expect($resolved->status)->toBe(DeliveryStatus::Sent)
                ->and(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            mailAmbiguityDrain($slot->refresh());

            expect(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(1);

            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->orderBy('id')
                ->pluck('status', 'channel')
                ->all();

            expect($deliveries[DigestMailChannel::class])->toBe(DeliveryStatus::Sent)
                ->and($deliveries[InboxChannel::class])->toBe(DeliveryStatus::Sent);

            $reconciled = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T09:00:00Z'));

            expect($reconciled['reconciled'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
                ->and(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);
        } finally {
            app()->bind(CommunicationRecorder::class, CommunicationRecorderService::class);

            if (is_file($observer)) {
                unlink($observer);
            }

            RewardFinanceWorkerDb::tearDown($mailDriver, $database, $previous);
        }
    });

    it("holds transport timeout after acceptance as UNKNOWN without auto-fail on {$mailLabel}", function () use ($mailDriver): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp($mailDriver, 'mailtrn');

        $observer = mailAmbiguityObserverFile($mailDriver.'-trn');

        if (is_file($observer)) {
            unlink($observer);
        }

        Queue::fake();

        $mailer = Mail::mailer();
        $previousTransport = $mailer->getSymfonyTransport();
        $arrayInner = $previousTransport instanceof ArrayTransport ? $previousTransport : new ArrayTransport;
        $arrayInner->flush();

        try {
            [$user] = mailAmbiguityDueSlot('mail-trn', 'mail-trn-source-1');
            $memberId = (string) $user->getKey();

            $slot = RewardDigestSlot::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', $memberId)
                ->firstOrFail();

            $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:00:00Z'));

            expect($outcome['dispatched'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(mailAmbiguityFileCount($observer))->toBe(0);

            $communicationId = (string) $slot->communication_id;
            $mailDeliveryId = (string) CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->where('channel', DigestMailChannel::class)
                ->value('id');

            $mailer->setSymfonyTransport(new DigestMailAcceptThenThrowTransport($observer, $arrayInner));

            try {
                mailAmbiguityDrain($slot->refresh());
                $crashed = false;
            } catch (TransportException $exception) {
                $crashed = $exception->getMessage() === 'simulated transport timeout after provider acceptance';
            }

            expect($crashed)->toBeTrue()
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $mailDelivery = CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail();
            $attempt = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail();

            expect($mailDelivery->status)->toBe(DeliveryStatus::Sending)
                ->and($mailDelivery->failed_at)->toBeNull()
                ->and(CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->count())->toBe(1)
                ->and($attempt->responded_at)->toBeNull()
                ->and($attempt->failed_at)->toBeNull()
                ->and(GuardedDeliverySend::isAmbiguousExternal($mailDeliveryId))->toBeTrue()
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(0);

            expect(fn () => CancelRewardDigestAction::run(
                $slot->refresh(), 'too-late', 'user', RewardLedgerFixtures::staffId(), 'staff', true,
                CarbonImmutable::parse('2026-06-23T08:05:00Z'),
            ))->toThrow(InvalidArgumentException::class, 'in flight');

            $held = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:30:00Z'));

            expect($held['reconciled'])->toBe(0)
                ->and($held['cancelled'])->toBe(0)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $actorId = RewardLedgerFixtures::staffId();

            $resolved = ResolveDigestMailAmbiguityAction::run(
                $mailDeliveryId, 'sent', 'user', $actorId, 'staff', 'observed-transport-1',
            );

            expect($resolved->status)->toBe(DeliveryStatus::Sent)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $resolution = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail()->metadata['digest_mail_resolution'] ?? null;

            expect($resolution)->toBeArray()
                ->and($resolution['decision'])->toBe('sent')
                ->and($resolution['actor_id'])->toBe($actorId)
                ->and($resolution['provider_message_id'])->toBe('observed-transport-1')
                ->and($resolution['resolved_at'] ?? null)->not->toBeNull();

            mailAmbiguityDrain($slot->refresh());

            expect(mailAmbiguityFileCount($observer))->toBe(1)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(1);

            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->orderBy('id')
                ->pluck('status', 'channel')
                ->all();

            expect($deliveries[DigestMailChannel::class])->toBe(DeliveryStatus::Sent)
                ->and($deliveries[InboxChannel::class])->toBe(DeliveryStatus::Sent);

            $reconciled = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T09:00:00Z'));

            expect($reconciled['reconciled'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);
        } finally {
            $mailer->setSymfonyTransport($previousTransport);

            if (is_file($observer)) {
                unlink($observer);
            }

            RewardFinanceWorkerDb::tearDown($mailDriver, $database, $previous);
        }
    });

    it("holds logic-class transport errors as UNKNOWN without auto-fail on {$mailLabel}", function () use ($mailDriver): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp($mailDriver, 'maildef');

        $observer = mailAmbiguityObserverFile($mailDriver.'-def');

        if (is_file($observer)) {
            unlink($observer);
        }

        Queue::fake();

        $mailer = Mail::mailer();
        $previousTransport = $mailer->getSymfonyTransport();
        $arrayInner = $previousTransport instanceof ArrayTransport ? $previousTransport : new ArrayTransport;
        $arrayInner->flush();

        try {
            [$user] = mailAmbiguityDueSlot('mail-def', 'mail-def-source-1');
            $memberId = (string) $user->getKey();

            $slot = RewardDigestSlot::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', $memberId)
                ->firstOrFail();

            $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:00:00Z'));

            expect($outcome['dispatched'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(mailAmbiguityFileCount($observer))->toBe(0)
                ->and(mailAmbiguityMailCount())->toBe(0);

            $communicationId = (string) $slot->communication_id;
            $mailDeliveryId = (string) CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->where('channel', DigestMailChannel::class)
                ->value('id');

            $mailer->setSymfonyTransport(new DigestMailLogicThrowTransport($arrayInner));

            try {
                mailAmbiguityDrain($slot->refresh());
                $crashed = false;
            } catch (InvalidArgumentException $exception) {
                $crashed = $exception->getMessage() === 'simulated logic failure inside transport: invalid recipient';
            }

            // The exception class proves nothing about delivery: a
            // logic-class error inside the send still holds UNKNOWN.
            expect($crashed)->toBeTrue()
                ->and(mailAmbiguityFileCount($observer))->toBe(0)
                ->and(mailAmbiguityMailCount())->toBe(0);

            $mailDelivery = CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail();
            $attempt = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail();

            expect($mailDelivery->status)->toBe(DeliveryStatus::Sending)
                ->and($mailDelivery->failed_at)->toBeNull()
                ->and($attempt->responded_at)->toBeNull()
                ->and($attempt->failed_at)->toBeNull()
                ->and(GuardedDeliverySend::isAmbiguousExternal($mailDeliveryId))->toBeTrue()
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(0);

            expect(fn () => CancelRewardDigestAction::run(
                $slot->refresh(), 'too-late', 'user', RewardLedgerFixtures::staffId(), 'staff', true,
                CarbonImmutable::parse('2026-06-23T08:05:00Z'),
            ))->toThrow(InvalidArgumentException::class, 'in flight');

            $held = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:30:00Z'));

            expect($held['reconciled'])->toBe(0)
                ->and($held['cancelled'])->toBe(0)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);

            // No acceptance was ever observed, so the only truthful
            // resolution is failed with verified non-delivery evidence.
            $actorId = RewardLedgerFixtures::staffId();

            $resolved = ResolveDigestMailAmbiguityAction::run(
                $mailDeliveryId, 'failed', 'user', $actorId, 'staff', null, 'transport rejected before acceptance; logs show no DATA',
            );

            expect($resolved->status)->toBe(DeliveryStatus::Failed)
                ->and(mailAmbiguityMailCount())->toBe(0)
                ->and(mailAmbiguityFileCount($observer))->toBe(0);

            $resolution = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail()->metadata['digest_mail_resolution'] ?? null;

            expect($resolution)->toBeArray()
                ->and($resolution['decision'])->toBe('failed')
                ->and($resolution['actor_id'])->toBe($actorId)
                ->and($resolution['reason'])->toBe('transport rejected before acceptance; logs show no DATA')
                ->and($resolution['resolved_at'] ?? null)->not->toBeNull();

            mailAmbiguityDrain($slot->refresh());

            // The failed aggregate blocks the queued sibling: no mail
            // resend, and the inbox copy cannot claim a Failed
            // aggregate. The slot stays held, never released. The inbox
            // row shows the second attempt's pre-send flip, still
            // unattempted and unwritten.
            expect(mailAmbiguityMailCount())->toBe(0)
                ->and(mailAmbiguityFileCount($observer))->toBe(0)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(0);

            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->orderBy('id')
                ->pluck('status', 'channel')
                ->all();

            expect($deliveries[DigestMailChannel::class])->toBe(DeliveryStatus::Failed)
                ->and($deliveries[InboxChannel::class])->toBe(DeliveryStatus::Sending)
                ->and(Communication::query()->whereKey($communicationId)->firstOrFail()->status)->toBe(CommunicationStatus::Failed);

            $heldAgain = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T09:00:00Z'));

            expect($heldAgain['reconciled'])->toBe(0)
                ->and($heldAgain['cancelled'])->toBe(0)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);
        } finally {
            $mailer->setSymfonyTransport($previousTransport);

            if (is_file($observer)) {
                unlink($observer);
            }

            RewardFinanceWorkerDb::tearDown($mailDriver, $database, $previous);
        }
    });

    it("holds logic failure after acceptance as UNKNOWN without auto-fail on {$mailLabel}", function () use ($mailDriver): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp($mailDriver, 'maillog');

        $observer = mailAmbiguityObserverFile($mailDriver.'-log');

        if (is_file($observer)) {
            unlink($observer);
        }

        Queue::fake();

        $mailer = Mail::mailer();
        $previousTransport = $mailer->getSymfonyTransport();
        $arrayInner = $previousTransport instanceof ArrayTransport ? $previousTransport : new ArrayTransport;
        $arrayInner->flush();

        try {
            [$user] = mailAmbiguityDueSlot('mail-log', 'mail-log-source-1');
            $memberId = (string) $user->getKey();

            $slot = RewardDigestSlot::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', $memberId)
                ->firstOrFail();

            $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:00:00Z'));

            expect($outcome['dispatched'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(mailAmbiguityFileCount($observer))->toBe(0);

            $communicationId = (string) $slot->communication_id;
            $mailDeliveryId = (string) CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->where('channel', DigestMailChannel::class)
                ->value('id');

            $mailer->setSymfonyTransport(new DigestMailAcceptThenThrowLogicTransport($observer, $arrayInner));

            try {
                mailAmbiguityDrain($slot->refresh());
                $crashed = false;
            } catch (LogicException $exception) {
                $crashed = $exception->getMessage() === 'simulated logic failure after provider acceptance';
            }

            expect($crashed)->toBeTrue()
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $mailDelivery = CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail();
            $attempt = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail();

            expect($mailDelivery->status)->toBe(DeliveryStatus::Sending)
                ->and($mailDelivery->failed_at)->toBeNull()
                ->and(CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->count())->toBe(1)
                ->and($attempt->responded_at)->toBeNull()
                ->and($attempt->failed_at)->toBeNull()
                ->and(GuardedDeliverySend::isAmbiguousExternal($mailDeliveryId))->toBeTrue()
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(0);

            expect(fn () => CancelRewardDigestAction::run(
                $slot->refresh(), 'too-late', 'user', RewardLedgerFixtures::staffId(), 'staff', true,
                CarbonImmutable::parse('2026-06-23T08:05:00Z'),
            ))->toThrow(InvalidArgumentException::class, 'in flight');

            $held = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:30:00Z'));

            expect($held['reconciled'])->toBe(0)
                ->and($held['cancelled'])->toBe(0)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $actorId = RewardLedgerFixtures::staffId();

            $resolved = ResolveDigestMailAmbiguityAction::run(
                $mailDeliveryId, 'sent', 'user', $actorId, 'staff', 'observed-logic-1',
            );

            expect($resolved->status)->toBe(DeliveryStatus::Sent)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $resolution = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail()->metadata['digest_mail_resolution'] ?? null;

            expect($resolution)->toBeArray()
                ->and($resolution['decision'])->toBe('sent')
                ->and($resolution['actor_id'])->toBe($actorId)
                ->and($resolution['provider_message_id'])->toBe('observed-logic-1')
                ->and($resolution['resolved_at'] ?? null)->not->toBeNull();

            mailAmbiguityDrain($slot->refresh());

            expect(mailAmbiguityFileCount($observer))->toBe(1)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(1);

            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->orderBy('id')
                ->pluck('status', 'channel')
                ->all();

            expect($deliveries[DigestMailChannel::class])->toBe(DeliveryStatus::Sent)
                ->and($deliveries[InboxChannel::class])->toBe(DeliveryStatus::Sent);

            $reconciled = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T09:00:00Z'));

            expect($reconciled['reconciled'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);
        } finally {
            $mailer->setSymfonyTransport($previousTransport);

            if (is_file($observer)) {
                unlink($observer);
            }

            RewardFinanceWorkerDb::tearDown($mailDriver, $database, $previous);
        }
    });

    it("holds post-send listener errors after acceptance as UNKNOWN on {$mailLabel}", function () use ($mailDriver): void {
        [$database, $previous] = RewardFinanceWorkerDb::setUp($mailDriver, 'mailerr');

        $observer = mailAmbiguityObserverFile($mailDriver.'-err');

        if (is_file($observer)) {
            unlink($observer);
        }

        Queue::fake();

        $mailer = Mail::mailer();
        $previousTransport = $mailer->getSymfonyTransport();
        $arrayInner = $previousTransport instanceof ArrayTransport ? $previousTransport : new ArrayTransport;
        $arrayInner->flush();

        // A post-send callback that observes the accepted message and
        // then throws an Error: acceptance already happened, so the
        // error class must not become a failure guess.
        Event::listen(MessageSent::class, function () use ($observer): void {
            file_put_contents($observer, "sent\n", FILE_APPEND);

            throw new Error('simulated post-send listener error');
        });

        try {
            [$user] = mailAmbiguityDueSlot('mail-err', 'mail-err-source-1');
            $memberId = (string) $user->getKey();

            $slot = RewardDigestSlot::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', $memberId)
                ->firstOrFail();

            $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:00:00Z'));

            expect($outcome['dispatched'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(mailAmbiguityMailCount())->toBe(0)
                ->and(mailAmbiguityFileCount($observer))->toBe(0);

            $communicationId = (string) $slot->communication_id;
            $mailDeliveryId = (string) CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->where('channel', DigestMailChannel::class)
                ->value('id');

            try {
                mailAmbiguityDrain($slot->refresh());
                $crashed = false;
            } catch (Error $exception) {
                $crashed = $exception->getMessage() === 'simulated post-send listener error';
            }

            // The message reached the transport (ArrayTransport holds
            // it) before the listener threw: accepted but unrecorded.
            expect($crashed)->toBeTrue()
                ->and(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $mailDelivery = CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail();
            $attempt = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail();

            expect($mailDelivery->status)->toBe(DeliveryStatus::Sending)
                ->and($mailDelivery->failed_at)->toBeNull()
                ->and(CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->count())->toBe(1)
                ->and($attempt->responded_at)->toBeNull()
                ->and($attempt->failed_at)->toBeNull()
                ->and(GuardedDeliverySend::isAmbiguousExternal($mailDeliveryId))->toBeTrue()
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(0);

            expect(fn () => CancelRewardDigestAction::run(
                $slot->refresh(), 'too-late', 'user', RewardLedgerFixtures::staffId(), 'staff', true,
                CarbonImmutable::parse('2026-06-23T08:05:00Z'),
            ))->toThrow(InvalidArgumentException::class, 'in flight');

            $held = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:30:00Z'));

            expect($held['reconciled'])->toBe(0)
                ->and($held['cancelled'])->toBe(0)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
                ->and(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            $actorId = RewardLedgerFixtures::staffId();

            $resolved = ResolveDigestMailAmbiguityAction::run(
                $mailDeliveryId, 'sent', 'user', $actorId, 'staff', 'observed-postsend-1',
            );

            expect($resolved->status)->toBe(DeliveryStatus::Sent)
                ->and(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);

            // The throwing listener must not leak into later tests.
            Event::getFacadeRoot()->forget(MessageSent::class);

            mailAmbiguityDrain($slot->refresh());

            expect(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1)
                ->and(NotificationInbox::query()->where('recipient_id', $memberId)->count())->toBe(1);

            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->orderBy('id')
                ->pluck('status', 'channel')
                ->all();

            expect($deliveries[DigestMailChannel::class])->toBe(DeliveryStatus::Sent)
                ->and($deliveries[InboxChannel::class])->toBe(DeliveryStatus::Sent);

            $reconciled = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T09:00:00Z'));

            expect($reconciled['reconciled'])->toBe(1)
                ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENT)
                ->and(mailAmbiguityMailCount())->toBe(1)
                ->and(mailAmbiguityFileCount($observer))->toBe(1);
        } finally {
            Event::getFacadeRoot()->forget(MessageSent::class);

            $mailer->setSymfonyTransport($previousTransport);

            if (is_file($observer)) {
                unlink($observer);
            }

            RewardFinanceWorkerDb::tearDown($mailDriver, $database, $previous);
        }
    });
}
