<?php

declare(strict_types=1);

namespace App\Support\Communications;

use AIArmada\Communications\Models\CommunicationPreference;
use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\NotificationInbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Interim package-model adapter for root policy-consent and inbox reads.
 *
 * Notification preference policy and locale/timezone reads now use the
 * Communications feature API. Inbox reads remain for their later migration slice.
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

    /**
     * @return Builder<NotificationInbox>
     */
    public function inboxQuery(string $accountId): Builder
    {
        return NotificationInbox::query()
            ->where('recipient_type', (new User)->getMorphClass())
            ->where('recipient_id', $accountId);
    }

    /**
     * @return MorphMany<NotificationInbox, User>
     */
    public function inboxRelationFor(User $user): MorphMany
    {
        return $user->morphMany(NotificationInbox::class, 'recipient');
    }

    public function unreadInboxCount(string $accountId, bool $excludeArchived = true): int
    {
        $query = $this->inboxQuery($accountId)->whereNull('read_at');

        if ($excludeArchived) {
            $query->whereNull('archived_at');
        }

        return $query->count();
    }
}
