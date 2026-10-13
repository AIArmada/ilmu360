<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\CommunicationAttempt;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Rewards\Support\RewardLedgerEntries;
use App\Support\Communications\GuardedDeliverySend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Resolve an UNKNOWN/ambiguous external digest-mail delivery with an
 * explicit operator decision and evidence.
 *
 * SMTP acceptance followed by a recording crash, or a transport
 * timeout/disconnect after external I/O started, leaves a
 * started-but-unresolved attempt and a Sending delivery that automatic
 * paths must never resend, fail, or release. This is the only writer
 * that may settle such ambiguity besides a provider confirmation
 * carrying a message id: the operator states sent (with the provider
 * message id observed in mail logs) or failed (with verified
 * non-delivery evidence/reason after confirming the provider never
 * accepted), and the fenced recording applies it only when the owning
 * attempt still holds. Anything already settled, non-ambiguous, or
 * superseded refuses instead of guessing. The resolution stamps
 * actor/reason/time audit onto the owning attempt metadata alongside
 * the existing fence, and never transmits mail.
 *
 * Production operators reach this through
 * rewards:resolve-digest-mail-ambiguity, which verifies the caller's
 * real staff/admin identity before delegating here.
 */
final readonly class ResolveDigestMailAmbiguityAction
{
    use AsAction;

    public function handle(
        string $deliveryId,
        string $decision,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?string $providerMessageId = null,
        ?string $reason = null,
    ): CommunicationDelivery {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

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
        } else {
            if ($reason === null || $reason === '' || mb_strlen($reason) > 191) {
                throw new InvalidArgumentException('Resolving ambiguity as failed requires a reason (1..191 characters).');
            }
        }

        return DB::transaction(function () use (
            $deliveryId, $decision, $actorType, $actorId, $actorRole, $providerMessageId, $reason
        ): CommunicationDelivery {
            $delivery = CommunicationDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

            if (! $delivery instanceof CommunicationDelivery) {
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

            $communicationId = (string) $delivery->communication_id;
            $attemptId = (string) $attempt->getKey();

            $applied = $decision === 'sent'
                ? GuardedDeliverySend::recordExternalSent($communicationId, $deliveryId, $attemptId, [
                    'provider_message_id' => (string) $providerMessageId,
                ])
                : GuardedDeliverySend::recordExternalFailure(
                    $communicationId, $deliveryId, $attemptId,
                    (string) $reason, 'operator-resolved',
                );

            if (! $applied) {
                throw new InvalidArgumentException('Ambiguity fencing refused; the delivery settled or changed under the lock.');
            }

            $resolved = CommunicationAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

            if ($resolved instanceof CommunicationAttempt) {
                $metadata = is_array($resolved->metadata) ? $resolved->metadata : [];
                $metadata['digest_mail_resolution'] = [
                    'decision' => $decision,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'actor_role' => $actorRole,
                    'provider_message_id' => $decision === 'sent' ? (string) $providerMessageId : null,
                    'reason' => $decision === 'failed' ? (string) $reason : null,
                    'resolved_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ];
                $resolved->metadata = $metadata;
                $resolved->save();
            }

            return $delivery->refresh();
        });
    }
}
