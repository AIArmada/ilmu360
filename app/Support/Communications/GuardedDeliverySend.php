<?php

declare(strict_types=1);

namespace App\Support\Communications;

use AIArmada\Communications\Actions\CompleteDeliveryAttemptAction;
use AIArmada\Communications\Actions\StartDeliveryAttemptAction;
use AIArmada\Communications\Contracts\CommunicationRecorder;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationAttempt;
use AIArmada\Communications\Models\CommunicationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;

/**
 * Own the channel/cancel race for context-bound deliveries.
 *
 * A send job and a correction cancel can race on the same queued
 * delivery: the job sends through its channel while the cancel marks
 * the transport cancelled. Both sides serialize on the delivery row —
 * cancel locks it before inspecting (see CancelRewardDigestAction) and
 * guarded channels claim it here before sending — so exactly one wins.
 * Skips detach the channel's context entry so the post-send listener
 * cannot record a send that never happened.
 *
 * Database channels (inbox) claim, write, and record Sent under one
 * lock: the write is transactional, so rollback truly undoes it. The
 * claim accepts Sending as well as pre-send states because the
 * package's pre-send listener marks the delivery Sending immediately
 * before the channel runs; the lock still serializes rival claimants,
 * so the second claimant always observes Sent and skips.
 *
 * External channels (mail) must never hold SMTP inside a DB
 * transaction: row locks cannot make provider acceptance reversible.
 * They claim plus commit a durable attempt boundary BEFORE external
 * I/O, freeze the stable operation/recipient snapshot, send outside
 * any transaction, then fence the recording against the owning
 * attempt. A Sending delivery with a started-but-unresolved attempt
 * is UNKNOWN/ambiguous: it can never automatically resend or release
 * a slot, and only an authoritative resolution (provider confirmation
 * or an explicit operator decision with evidence) may settle it.
 * Unsupported SMTP ambiguity stays operator-resolvable, never a
 * guessed failure or success.
 */
final class GuardedDeliverySend
{
    public static function deliveryIdFor(Notification $notification, string $channel): ?string
    {
        if (! method_exists($notification, 'deliveryIdForChannel')) {
            return null;
        }

        $id = $notification->deliveryIdForChannel($channel);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function communicationIdOf(Notification $notification): ?string
    {
        if (! method_exists($notification, 'communicationId')) {
            return null;
        }

        $id = $notification->communicationId();

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Lock the bound delivery (and its communication) and return it only
     * when the send may proceed. Returns null when another worker owns
     * or settled the send, or when the transport was cancelled first.
     * A pre-send Sending flip over an already-settled status is
     * restored before returning null, so a duplicate job never leaves
     * a Sent/Failed delivery showing live work.
     */
    public static function claim(string $deliveryId, ?string $communicationId): ?CommunicationDelivery
    {
        $delivery = CommunicationDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

        if (! $delivery instanceof CommunicationDelivery) {
            return null;
        }

        $communication = $communicationId === null
            ? null
            : Communication::query()->whereKey($communicationId)->lockForUpdate()->first();

        if (! $communication instanceof Communication) {
            self::restoreSettledFlip($delivery);

            return null;
        }

        // A cancelled aggregate stays cancelled even when the pre-send
        // listener or a late record flips a status afterwards:
        // cancelled_at is the tombstone those flips never clear, and a
        // delivery that legitimately sent can never carry one (the
        // cancel update only touches unsent work).
        if ($communication->cancelled_at !== null || $delivery->cancelled_at !== null) {
            self::restoreCancel($delivery, $communication);

            return null;
        }

        if (! self::isSendable($communication)) {
            self::restoreSettledFlip($delivery);

            return null;
        }

        if ($delivery->status instanceof DeliveryStatus && in_array($delivery->status, [
            DeliveryStatus::Pending,
            DeliveryStatus::Scheduled,
            DeliveryStatus::Queued,
            DeliveryStatus::Sending,
        ], true)) {
            return $delivery;
        }

        self::restoreSettledFlip($delivery);

        return null;
    }

    public static function detach(Notification $notification, string $channel): void
    {
        if (property_exists($notification, 'deliveryIdsByChannel')
            && is_array($notification->deliveryIdsByChannel)) {
            unset($notification->deliveryIdsByChannel[$channel]);
        }
    }

    /**
     * Claim a delivery for EXTERNAL I/O (mail): same cancellation and
     * sendability guards as claim(), but at-most-once per delivery.
     * Sending is accepted only when no attempt exists yet (the
     * pre-send listener flip before the first attempt). A Sending
     * delivery with any started attempt is UNKNOWN/ambiguous — the
     * provider may already have accepted — so this returns null and
     * the caller must skip without transmitting. Must run inside the
     * caller's claim transaction holding the delivery lock.
     */
    public static function claimExternal(string $deliveryId, ?string $communicationId): ?CommunicationDelivery
    {
        $delivery = self::claim($deliveryId, $communicationId);

        if (! $delivery instanceof CommunicationDelivery) {
            return null;
        }

        if (self::hasAnyAttempt($deliveryId)) {
            self::restoreSettledFlip($delivery);

            return null;
        }

        return $delivery;
    }

    /**
     * Commit the durable attempt/ownership boundary for an external
     * send. Freezes the stable operation/recipient snapshot into the
     * attempt metadata and returns the owning attempt. The caller must
     * hold the delivery lock (claimExternal) in the same transaction;
     * this commits with that transaction BEFORE any external I/O.
     *
     * @param  array<string, mixed>  $operation  Stable operation identity (plan_key, slot_id, payload hash).
     */
    public static function startExternalAttempt(
        string $deliveryId,
        CommunicationDelivery $delivery,
        array $operation = [],
    ): CommunicationAttempt {
        $frozen = [
            'delivery_id' => $deliveryId,
            'communication_id' => (string) $delivery->communication_id,
            'channel' => (string) $delivery->channel,
            'provider' => (string) $delivery->provider,
            'destination_hash' => $delivery->destination_hash,
            'content_id' => $delivery->content_id,
            'operation' => $operation,
        ];

        $attempt = app(StartDeliveryAttemptAction::class)->handle($deliveryId, [
            'channel' => (string) $delivery->channel,
            'destination_hash' => $delivery->destination_hash,
        ]);

        $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
        $metadata['digest_mail_fence'] = $frozen;
        $attempt->metadata = $metadata;
        $attempt->save();

        return $attempt->refresh();
    }

    /**
     * Fenced Sent recording for an external send that returned success.
     * Returns true when the Sent is durably recorded (or was already),
     * false when fencing refused (cancelled, settled otherwise,
     * mismatched snapshot, or superseded attempt) and the caller must
     * skip without overwriting. Never throws for fencing; throws only
     * for genuine persistence failures, which leave the attempt
     * ambiguous (started, no response) and the delivery Sending.
     *
     * @param  array<string, mixed>  $result
     */
    public static function recordExternalSent(
        string $communicationId,
        string $deliveryId,
        string $attemptId,
        array $result = [],
    ): bool {
        $delivery = CommunicationDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
        $communication = Communication::query()->whereKey($communicationId)->lockForUpdate()->first();
        $attempt = CommunicationAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

        if (! $delivery instanceof CommunicationDelivery
            || ! $communication instanceof Communication
            || ! $attempt instanceof CommunicationAttempt
            || (string) $attempt->delivery_id !== $deliveryId
        ) {
            return false;
        }

        if ($communication->cancelled_at !== null || $delivery->cancelled_at !== null) {
            self::restoreCancel($delivery, $communication);

            return false;
        }

        if (self::isDeliveredStatus($delivery->status)) {
            if ($attempt->responded_at === null && $attempt->failed_at === null) {
                app(CompleteDeliveryAttemptAction::class)->handle(
                    $attemptId,
                    $result['provider_message_id'] ?? $delivery->provider_message_id,
                    null,
                    [],
                );
            }

            return true;
        }

        if ($delivery->status !== DeliveryStatus::Sending) {
            return false;
        }

        if (! self::attemptOwns($delivery, $attempt)) {
            return false;
        }

        if (! self::frozenMatches($delivery, $attempt)) {
            return false;
        }

        app(CompleteDeliveryAttemptAction::class)->handle(
            $attemptId,
            $result['provider_message_id'] ?? null,
            null,
            [],
        );

        app(CommunicationRecorder::class)->markSent($communicationId, $deliveryId, $result);

        return true;
    }

    /**
     * Fenced failure recording for a definitively unsent external
     * attempt: a pre-send rejection that proves no acceptance, or an
     * explicit operator failed decision with verified non-delivery
     * evidence. Returns true when Failed is recorded (or was already
     * terminal), false when fencing refused. Never regresses a
     * delivered status and never guesses failure for a transport
     * exception that may have followed acceptance — callers must
     * leave those ambiguous, never call this for post-acceptance
     * recording crashes or unknown transport errors.
     */
    public static function recordExternalFailure(
        string $communicationId,
        string $deliveryId,
        string $attemptId,
        string $failureMessage,
        ?string $failureCode = null,
    ): bool {
        $delivery = CommunicationDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
        $communication = Communication::query()->whereKey($communicationId)->lockForUpdate()->first();
        $attempt = CommunicationAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

        if (! $delivery instanceof CommunicationDelivery
            || ! $communication instanceof Communication
            || ! $attempt instanceof CommunicationAttempt
            || (string) $attempt->delivery_id !== $deliveryId
        ) {
            return false;
        }

        if ($communication->cancelled_at !== null || $delivery->cancelled_at !== null) {
            self::restoreCancel($delivery, $communication);

            return false;
        }

        if (self::isDeliveredStatus($delivery->status)) {
            return false;
        }

        if (in_array($delivery->status, [
            DeliveryStatus::Failed,
            DeliveryStatus::Cancelled,
            DeliveryStatus::Expired,
            DeliveryStatus::Suppressed,
            DeliveryStatus::Unsubscribed,
            DeliveryStatus::Bounced,
            DeliveryStatus::Complained,
        ], true)) {
            return true;
        }

        if (! self::attemptOwns($delivery, $attempt)) {
            return false;
        }

        if ($attempt->responded_at !== null || $attempt->failed_at !== null) {
            return false;
        }

        $attempt->failed_at = CarbonImmutable::now();
        $attempt->failure_code = $failureCode;
        $attempt->failure_message = mb_substr($failureMessage, 0, 2000);
        $attempt->save();

        app(CommunicationRecorder::class)->markFailed($communicationId, $deliveryId, $failureMessage);

        return true;
    }

    /**
     * True when the delivery carries an UNKNOWN/ambiguous external
     * attempt: started, neither responded nor failed. Such deliveries
     * must never automatically resend or release; only an authoritative
     * provider confirmation or an explicit operator decision may settle
     * them.
     */
    public static function isAmbiguousExternal(string $deliveryId): bool
    {
        return CommunicationAttempt::query()
            ->where('delivery_id', $deliveryId)
            ->whereNotNull('started_at')
            ->whereNull('responded_at')
            ->whereNull('failed_at')
            ->exists();
    }

    public static function hasAnyAttempt(string $deliveryId): bool
    {
        return CommunicationAttempt::query()->where('delivery_id', $deliveryId)->exists();
    }

    private static function attemptOwns(CommunicationDelivery $delivery, CommunicationAttempt $attempt): bool
    {
        return (int) $delivery->attempt_count === (int) $attempt->attempt_number
            && (string) $attempt->delivery_id === (string) $delivery->getKey();
    }

    private static function frozenMatches(CommunicationDelivery $delivery, CommunicationAttempt $attempt): bool
    {
        $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
        $fence = $metadata['digest_mail_fence'] ?? null;

        if (! is_array($fence)) {
            return true;
        }

        return ($fence['delivery_id'] ?? null) === (string) $delivery->getKey()
            && ($fence['communication_id'] ?? null) === (string) $delivery->communication_id
            && ($fence['channel'] ?? null) === (string) $delivery->channel
            && ($fence['provider'] ?? null) === (string) $delivery->provider
            && ($fence['destination_hash'] ?? null) === $delivery->destination_hash
            && ($fence['content_id'] ?? null) === $delivery->content_id;
    }

    private static function isDeliveredStatus(mixed $status): bool
    {
        return $status instanceof DeliveryStatus && in_array($status, [
            DeliveryStatus::Sent,
            DeliveryStatus::Received,
            DeliveryStatus::Delivered,
            DeliveryStatus::Opened,
            DeliveryStatus::Read,
            DeliveryStatus::Clicked,
            DeliveryStatus::Replied,
        ], true);
    }

    private static function isSendable(Communication $communication): bool
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

    /**
     * Restore post-cancel status flips (a Sending flip or a phantom Sent
     * recorded after the cancel won) so the cancelled aggregate never
     * shows live or delivered work. Diagnostic timestamps stay.
     */
    private static function restoreCancel(CommunicationDelivery $delivery, Communication $communication): void
    {
        if ($delivery->status !== DeliveryStatus::Cancelled) {
            $delivery->status = DeliveryStatus::Cancelled;
            $delivery->save();
        }

        if ($communication->status !== CommunicationStatus::Cancelled) {
            $communication->status = CommunicationStatus::Cancelled;
            $communication->save();
        }
    }

    /**
     * Restore a pre-send Sending flip over an already-settled delivery.
     * The package listener flips unconditionally before the channel
     * runs; when the channel then skips (settled, non-sendable
     * aggregate, or already-attempted external), the flip must not
     * leave a Sent/Failed delivery showing live work. Diagnostic
     * timestamps are the settled truth and stay; ambiguous Sending
     * with no settled timestamp is left untouched.
     */
    private static function restoreSettledFlip(CommunicationDelivery $delivery): void
    {
        if ($delivery->status !== DeliveryStatus::Sending) {
            return;
        }

        if ($delivery->cancelled_at !== null) {
            return;
        }

        $restored = match (true) {
            $delivery->replied_at !== null => DeliveryStatus::Replied,
            $delivery->clicked_at !== null => DeliveryStatus::Clicked,
            $delivery->read_at !== null => DeliveryStatus::Read,
            $delivery->opened_at !== null => DeliveryStatus::Opened,
            $delivery->delivered_at !== null => DeliveryStatus::Delivered,
            $delivery->received_at !== null => DeliveryStatus::Received,
            $delivery->sent_at !== null => DeliveryStatus::Sent,
            $delivery->failed_at !== null => DeliveryStatus::Failed,
            $delivery->bounced_at !== null => DeliveryStatus::Bounced,
            $delivery->complained_at !== null => DeliveryStatus::Complained,
            $delivery->expired_at !== null => DeliveryStatus::Expired,
            $delivery->suppressed_at !== null => DeliveryStatus::Suppressed,
            $delivery->unsubscribed_at !== null => DeliveryStatus::Unsubscribed,
            default => null,
        };

        if ($restored instanceof DeliveryStatus) {
            $delivery->status = $restored;
            $delivery->save();
        }
    }
}
