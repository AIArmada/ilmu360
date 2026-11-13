<?php

declare(strict_types=1);

namespace App\Features\Communications\Support\Integration;

use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;

/** Internal package-backed lookups for Communications delivery resolvers. */
final readonly class NotificationRecipientSettingsLookup
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
