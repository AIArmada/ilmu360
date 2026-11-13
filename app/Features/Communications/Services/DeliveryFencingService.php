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
        $deliveryIds = array_values(array_unique(array_filter(
            $deliveryIdsByChannel,
            static fn (string $deliveryId): bool => $deliveryId !== '',
        )));

        if ($deliveryIds === []) {
            return;
        }

        sort($deliveryIds, SORT_STRING);

        DB::transaction(function () use ($communicationId, $deliveryIds, $failureMessage): void {
            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->whereIn('id', $deliveryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (CommunicationDelivery $delivery): string => (string) $delivery->getKey());
            $communication = Communication::query()
                ->whereKey($communicationId)
                ->lockForUpdate()
                ->first();

            if (! $communication instanceof Communication || $communication->cancelled_at !== null) {
                return;
            }

            foreach ($deliveryIds as $deliveryId) {
                $delivery = $deliveries->get($deliveryId);

                if (! $delivery instanceof CommunicationDelivery || $delivery->cancelled_at !== null) {
                    continue;
                }

                if ($this->hasTerminalFailureEvidence($delivery)) {
                    continue;
                }

                if (GuardedDeliverySend::isAmbiguousExternal($deliveryId)) {
                    continue;
                }

                $this->recorder->markFailed($communicationId, $deliveryId, $failureMessage);
            }
        }, attempts: 3);
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
