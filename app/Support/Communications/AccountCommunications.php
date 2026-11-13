<?php

declare(strict_types=1);

namespace App\Support\Communications;

use AIArmada\Communications\Models\CommunicationPreference;
use AIArmada\Communications\Models\CommunicationDestination;

/**
 * Interim package-model adapter for root policy and consent reads.
 *
 * Notification preference policy and locale/timezone reads now use the
 * Communications feature API. Remaining reads move in the delivery-adapter slice.
 */
final readonly class AccountCommunications
{
    public function basePreferenceForRecipient(?string $recipientType, ?string $recipientId): ?CommunicationPreference
    {
        if ($recipientType === null || $recipientId === null) {
            return null;
        }

        return CommunicationPreference::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type')
            ->first();
    }

    public function hasActiveDestinationForRecipient(
        ?string $recipientType,
        ?string $recipientId,
        string $channel,
    ): bool {
        if ($recipientType === null || $recipientId === null) {
            return false;
        }

        return CommunicationDestination::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('channel', $channel)
            ->where('status', 'active')
            ->exists();
    }

}
