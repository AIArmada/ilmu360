<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Contracts\CommunicationRecorder;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationAttempt;
use AIArmada\Communications\Models\CommunicationDelivery;
use Carbon\CarbonImmutable;

/**
 * Persists fenced delivery transitions inside the caller's existing row locks
 * and transaction, without placing network I/O inside a database transaction.
 */
final readonly class PersistDeliveryFencingAction
{
    public function __construct(private CommunicationRecorder $recorder) {}

    /** @param array<string, mixed> $frozen */
    public function recordFrozenAttempt(CommunicationAttempt $attempt, array $frozen): void
    {
        $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
        $metadata['digest_mail_fence'] = $frozen;
        $attempt->metadata = $metadata;
        $attempt->save();
    }

    public function recordFailure(CommunicationAttempt $attempt, string $message, ?string $code): void
    {
        $attempt->failed_at = CarbonImmutable::now();
        $attempt->failure_code = $code;
        $attempt->failure_message = mb_substr($message, 0, 2000);
        $attempt->save();
    }

    /** @param array<string, mixed> $result */
    public function markSent(string $communicationId, string $deliveryId, array $result): void
    {
        $this->recorder->markSent($communicationId, $deliveryId, $result);
    }

    public function markFailed(string $communicationId, string $deliveryId, string $message): void
    {
        $this->recorder->markFailed($communicationId, $deliveryId, $message);
    }

    public function restoreCancelled(CommunicationDelivery $delivery, Communication $communication): void
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

    public function restoreSettledDelivery(CommunicationDelivery $delivery, DeliveryStatus $status): void
    {
        $delivery->status = $status;
        $delivery->save();
    }
}
