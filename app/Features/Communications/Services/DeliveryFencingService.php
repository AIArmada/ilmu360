<?php

namespace App\Features\Communications\Services;

use AIArmada\Communications\Contracts\CommunicationRecorder;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationDelivery;
use App\Features\Communications\Api\DeliveryFencing;
use App\Features\Communications\Support\Delivery\GuardedDeliverySend;
use Illuminate\Support\Facades\DB;

final class DeliveryFencingService implements DeliveryFencing
{
    public function __construct(private readonly CommunicationRecorder $recorder) {}

    public function recordExternalSent(
        string $communicationId,
        string $deliveryId,
        string $attemptId,
        array $result = [],
    ): bool {
        return GuardedDeliverySend::recordExternalSent($communicationId, $deliveryId, $attemptId, $result);
    }

    public function recordExternalFailure(
        string $communicationId,
        string $deliveryId,
        string $attemptId,
        string $failureMessage,
        ?string $failureCode = null,
    ): bool {
        return GuardedDeliverySend::recordExternalFailure(
            $communicationId,
            $deliveryId,
            $attemptId,
            $failureMessage,
            $failureCode,
        );
    }

    public function isAmbiguousExternal(string $deliveryId): bool
    {
        return GuardedDeliverySend::isAmbiguousExternal($deliveryId);
    }

    /** @param array<string, string> $deliveryIdsByChannel */
    public function recordNotificationFailure(
        string $communicationId,
        array $deliveryIdsByChannel,
        string $failureMessage,
    ): void {
        foreach ($deliveryIdsByChannel as $deliveryId) {
            if ($deliveryId === '') {
                continue;
            }

            DB::transaction(function () use ($communicationId, $deliveryId, $failureMessage): void {
                $delivery = CommunicationDelivery::query()
                    ->whereKey($deliveryId)
                    ->lockForUpdate()
                    ->first();
                $communication = Communication::query()
                    ->whereKey($communicationId)
                    ->lockForUpdate()
                    ->first();

                if (! $delivery instanceof CommunicationDelivery
                    || ! $communication instanceof Communication
                    || (string) $delivery->communication_id !== $communicationId
                    || $communication->cancelled_at !== null
                    || $delivery->cancelled_at !== null
                ) {
                    return;
                }

                if ($this->hasTerminalFailureEvidence($delivery)) {
                    return;
                }

                if (GuardedDeliverySend::isAmbiguousExternal($deliveryId)) {
                    return;
                }

                $this->recorder->markFailed($communicationId, $deliveryId, $failureMessage);
            }, attempts: 3);
        }
    }

    private function hasTerminalFailureEvidence(CommunicationDelivery $delivery): bool
    {
        if ($delivery->status instanceof DeliveryStatus && in_array($delivery->status, [
            DeliveryStatus::Accepted,
            DeliveryStatus::Sent,
            DeliveryStatus::Received,
            DeliveryStatus::Delivered,
            DeliveryStatus::Opened,
            DeliveryStatus::Read,
            DeliveryStatus::Clicked,
            DeliveryStatus::Replied,
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

        foreach ([
            'accepted_at',
            'sent_at',
            'received_at',
            'delivered_at',
            'opened_at',
            'read_at',
            'clicked_at',
            'replied_at',
            'failed_at',
            'expired_at',
            'suppressed_at',
            'unsubscribed_at',
            'bounced_at',
            'complained_at',
        ] as $timestamp) {
            if ($delivery->{$timestamp} !== null) {
                return true;
            }
        }

        return false;
    }
}
