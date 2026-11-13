<?php

declare(strict_types=1);

namespace App\Features\Communications\Services;

use AIArmada\Communications\Actions\CancelCommunicationAction;
use AIArmada\Communications\Actions\DispatchManagedNotificationAction;
use AIArmada\Communications\Data\CommunicationContextData;
use AIArmada\Communications\Enums\CommunicationCategory;
use AIArmada\Communications\Enums\CommunicationPriority;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Jobs\DispatchManagedNotificationJob;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationAttempt;
use AIArmada\Communications\Models\CommunicationDelivery;
use App\Features\Communications\Api\DeliveryFencing;
use App\Features\Communications\Api\ManagedCommunicationSnapshot;
use App\Features\Communications\Api\ManagedDeliveryResolutionSnapshot;
use App\Features\Communications\Api\ManagedNotificationDelivery;
use App\Features\Communications\Api\ManagedNotificationDispatchRequest;
use App\Features\Communications\Notifications\ManagedNotification;
use App\Shared\Auth\AccountDirectory;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ManagedNotificationDeliveryService implements ManagedNotificationDelivery
{
    public function __construct(
        private AccountDirectory $accounts,
        private DispatchManagedNotificationAction $dispatchNotification,
        private DeliveryFencing $deliveryFencing,
    ) {}

    public function adoptOrDispatch(ManagedNotificationDispatchRequest $request): ?ManagedCommunicationSnapshot
    {
        return DB::transaction(function () use ($request): ?ManagedCommunicationSnapshot {
            $recipient = $this->accounts->resolveNotifiable($request->recipientId);

            if ($recipient === null) {
                return null;
            }

            $candidateId = Communication::query()
                ->where('idempotency_key', $request->idempotencyKey)
                ->orderByDesc('id')
                ->value('id');

            if (is_string($candidateId) && $candidateId !== '') {
                $locked = $this->lockAggregate($candidateId);

                if ($locked !== null
                    && $this->isSendable($locked['communication'])
                    && $locked['deliveries']->isNotEmpty()
                ) {
                    $snapshot = $locked['snapshot'];

                    if ($snapshot->allPreSend
                        && $snapshot->queuedAt instanceof CarbonImmutable
                        && $snapshot->queuedAt->addMinutes($request->stalledMinutes)->lessThan($request->now)
                    ) {
                        $notification = new ManagedNotification($request->redriveMessage);
                        $deliveryIdsByChannel = [];

                        foreach ($locked['deliveries'] as $delivery) {
                            $deliveryIdsByChannel[(string) $delivery->channel] = (string) $delivery->getKey();
                        }

                        $notification->withCommunicationContext(
                            (string) $locked['communication']->getKey(),
                            $deliveryIdsByChannel,
                        );

                        DispatchManagedNotificationJob::dispatch(
                            notifiable: $recipient,
                            notification: $notification,
                            channels: array_keys($deliveryIdsByChannel),
                        )->afterCommit();
                    }

                    return $snapshot;
                }
            }

            $notification = new ManagedNotification($request->message);
            $communication = $this->dispatchNotification->handle(
                $recipient,
                $notification,
                new CommunicationContextData(
                    category: CommunicationCategory::from($request->category),
                    priority: CommunicationPriority::from($request->priority),
                    timezone: $request->timezone,
                    idempotencyKey: $request->idempotencyKey,
                    metadata: $request->metadata,
                ),
            );
            $created = $this->lockAggregate((string) $communication->getKey());

            return $created !== null && $created['deliveries']->isNotEmpty()
                ? $created['snapshot']
                : null;
        });
    }

    public function snapshot(string $communicationId): ?ManagedCommunicationSnapshot
    {
        return DB::transaction(fn (): ?ManagedCommunicationSnapshot => $this->lockAggregate($communicationId)['snapshot'] ?? null);
    }

    public function cancelBeforeSend(string $communicationId, string $reason): bool
    {
        return DB::transaction(function () use ($communicationId, $reason): bool {
            $locked = $this->lockAggregate($communicationId);

            if ($locked === null) {
                return false;
            }

            foreach ($locked['deliveries'] as $delivery) {
                if ($this->isDelivered($delivery->status)) {
                    throw new InvalidArgumentException('Digest already delivered on at least one channel; cancellation is closed.');
                }

                if ($delivery->status instanceof DeliveryStatus && in_array($delivery->status, [
                    DeliveryStatus::Sending,
                    DeliveryStatus::Accepted,
                ], true)) {
                    throw new InvalidArgumentException('Digest delivery is in flight; reconcile first.');
                }
            }

            app(CancelCommunicationAction::class)->handle($communicationId, $reason);

            return true;
        });
    }

    public function resolveAmbiguousExternalDelivery(
        string $deliveryId,
        string $decision,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?string $providerMessageId = null,
        ?string $reason = null,
    ): ManagedDeliveryResolutionSnapshot {
        $this->assertActor($actorType, $actorId, $actorRole);

        $isSystem = $actorType === 'system' && $actorId === null && $actorRole === 'system';
        $isStaff = $actorType === 'user' && in_array($actorRole, ['staff', 'admin'], true);

        if (! $isSystem && ! $isStaff) {
            throw new InvalidArgumentException('Ambiguity resolution requires a staff/admin user or the system identity.');
        }

        if (! in_array($decision, ['sent', 'failed'], true)) {
            throw new InvalidArgumentException('Ambiguity decision must be sent or failed.');
        }

        if ($decision === 'sent') {
            if ($providerMessageId === null || $providerMessageId === '' || mb_strlen($providerMessageId) > 191) {
                throw new InvalidArgumentException('Resolving ambiguity as sent requires the observed provider message id (1..191 characters).');
            }
        } elseif ($reason === null || $reason === '' || mb_strlen($reason) > 191) {
            throw new InvalidArgumentException('Resolving ambiguity as failed requires a reason (1..191 characters).');
        }

        $communicationId = CommunicationDelivery::query()->whereKey($deliveryId)->value('communication_id');

        if (! is_string($communicationId) || $communicationId === '') {
            throw new InvalidArgumentException('Unknown mail delivery.');
        }

        return DB::transaction(function () use (
            $communicationId,
            $deliveryId,
            $decision,
            $actorType,
            $actorId,
            $actorRole,
            $providerMessageId,
            $reason,
        ): ManagedDeliveryResolutionSnapshot {
            $locked = $this->lockAggregate($communicationId);

            if ($locked === null) {
                throw new InvalidArgumentException('Unknown mail delivery.');
            }

            $delivery = $locked['deliveries']->first(
                static fn (CommunicationDelivery $candidate): bool => (string) $candidate->getKey() === $deliveryId,
            );

            if (! $delivery instanceof CommunicationDelivery
                || (string) $delivery->communication_id !== $communicationId
            ) {
                throw new InvalidArgumentException('Unknown mail delivery.');
            }

            if ($delivery->status !== DeliveryStatus::Sending) {
                throw new InvalidArgumentException('Delivery is not ambiguously sending; only Sending with an unresolved attempt may resolve.');
            }

            $attempt = CommunicationAttempt::query()
                ->where('delivery_id', $deliveryId)
                ->whereNotNull('started_at')
                ->whereNull('responded_at')
                ->whereNull('failed_at')
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if (! $attempt instanceof CommunicationAttempt
                || (int) $attempt->attempt_number !== (int) $delivery->attempt_count
            ) {
                throw new InvalidArgumentException('Delivery carries no unresolved owning attempt; nothing ambiguous to resolve.');
            }

            $attemptId = (string) $attempt->getKey();
            $applied = $decision === 'sent'
                ? $this->deliveryFencing->recordExternalSent($communicationId, $deliveryId, $attemptId, [
                    'provider_message_id' => (string) $providerMessageId,
                ])
                : $this->deliveryFencing->recordExternalFailure(
                    $communicationId,
                    $deliveryId,
                    $attemptId,
                    (string) $reason,
                    'operator-resolved',
                );

            if (! $applied) {
                throw new InvalidArgumentException('Ambiguity fencing refused; the delivery settled or changed under the lock.');
            }

            $resolvedAttempt = CommunicationAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

            if ($resolvedAttempt instanceof CommunicationAttempt) {
                $metadata = is_array($resolvedAttempt->metadata) ? $resolvedAttempt->metadata : [];
                $metadata['digest_mail_resolution'] = [
                    'decision' => $decision,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'actor_role' => $actorRole,
                    'provider_message_id' => $decision === 'sent' ? (string) $providerMessageId : null,
                    'reason' => $decision === 'failed' ? (string) $reason : null,
                    'resolved_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ];
                $resolvedAttempt->metadata = $metadata;
                $resolvedAttempt->save();
            }

            $resolvedDelivery = $delivery->refresh();

            return new ManagedDeliveryResolutionSnapshot(
                deliveryId: (string) $resolvedDelivery->getKey(),
                status: $resolvedDelivery->status->value,
            );
        });
    }

    /**
     * @return array{
     *     communication: Communication,
     *     deliveries: Collection<int, CommunicationDelivery>,
     *     snapshot: ManagedCommunicationSnapshot
     * }|null
     */
    private function lockAggregate(string $communicationId): ?array
    {
        $deliveries = CommunicationDelivery::query()
            ->where('communication_id', $communicationId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $communication = Communication::query()->whereKey($communicationId)->lockForUpdate()->first();

        if (! $communication instanceof Communication) {
            return null;
        }

        $channelStatuses = [];
        $hasDelivered = false;
        $hasInFlight = false;
        $hasAmbiguous = false;
        $allPreSend = $deliveries->isNotEmpty();

        foreach ($deliveries as $delivery) {
            $status = $delivery->status instanceof DeliveryStatus
                ? $delivery->status
                : DeliveryStatus::tryFrom((string) $delivery->status);
            $channelStatuses[(string) $delivery->channel] = $status?->value ?? (string) $delivery->status;
            $hasDelivered = $hasDelivered || $this->isDelivered($status);
            $hasInFlight = $hasInFlight || $this->isInFlight($status);
            $hasAmbiguous = $hasAmbiguous || $this->isAmbiguous($status);
            $allPreSend = $allPreSend && $this->isPreSend($status);
        }

        $queuedAt = $communication->queued_at ?? $communication->created_at;
        $queuedAt = $queuedAt instanceof DateTimeInterface
            ? CarbonImmutable::instance($queuedAt)
            : null;

        return [
            'communication' => $communication,
            'deliveries' => $deliveries,
            'snapshot' => new ManagedCommunicationSnapshot(
                communicationId: (string) $communication->getKey(),
                queuedAt: $queuedAt,
                channelStatuses: $channelStatuses,
                hasDelivered: $hasDelivered,
                hasInFlight: $hasInFlight,
                hasAmbiguous: $hasAmbiguous,
                allPreSend: $allPreSend,
            ),
        ];
    }

    private function isSendable(Communication $communication): bool
    {
        return $communication->status instanceof CommunicationStatus
            && in_array($communication->status, [
                CommunicationStatus::Draft,
                CommunicationStatus::Scheduled,
                CommunicationStatus::Queued,
                CommunicationStatus::Processing,
                CommunicationStatus::PartiallyCompleted,
            ], true);
    }

    private function isPreSend(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [
                DeliveryStatus::Pending,
                DeliveryStatus::Scheduled,
                DeliveryStatus::Queued,
            ], true);
    }

    private function isDelivered(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [
                DeliveryStatus::Sent,
                DeliveryStatus::Received,
                DeliveryStatus::Delivered,
                DeliveryStatus::Opened,
                DeliveryStatus::Read,
                DeliveryStatus::Clicked,
                DeliveryStatus::Replied,
            ], true);
    }

    private function isInFlight(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [
                DeliveryStatus::Pending,
                DeliveryStatus::Scheduled,
                DeliveryStatus::Queued,
                DeliveryStatus::Sending,
                DeliveryStatus::Accepted,
            ], true);
    }

    private function isAmbiguous(mixed $status): bool
    {
        return $status instanceof DeliveryStatus
            && in_array($status, [DeliveryStatus::Sending, DeliveryStatus::Accepted], true);
    }

    private function assertActor(string $actorType, ?string $actorId, string $actorRole): void
    {
        if (! in_array($actorType, ['user', 'system'], true)) {
            throw new InvalidArgumentException('Actor type must be one of [user,system].');
        }

        if (! in_array($actorRole, ['member', 'staff', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('Actor role must be one of [member,staff,admin,system].');
        }

        if ($actorType === 'system' && $actorId !== null) {
            throw new InvalidArgumentException('System actors must not carry an actor id.');
        }

        if ($actorType === 'user' && ($actorId === null || ! Str::isUuid($actorId))) {
            throw new InvalidArgumentException('User actors require an explicit UUID actor id.');
        }

        if ($actorType === 'user' && $actorRole === 'system') {
            throw new InvalidArgumentException('User actors must not use the system role.');
        }

        if ($actorType === 'system' && $actorRole !== 'system') {
            throw new InvalidArgumentException('System actors must use the system role.');
        }
    }
}
