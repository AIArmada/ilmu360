<?php

declare(strict_types=1);

namespace App\Support\Communications;

use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use AIArmada\Communications\Models\NotificationInbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Interim account communications read/write adapter.
 *
 * Communications will own preferences, destinations and inboxes once the
 * feature is extracted. Until then this service replaces the removed User
 * reverse relations with the same recipient-scoped queries.
 */
final readonly class AccountCommunications
{
    public function preferredLocale(string $accountId): string
    {
        $locale = $this->basePreferenceQuery($accountId)->value('locale');

        return is_string($locale) && $locale !== ''
            ? $locale
            : (string) config('app.locale');
    }

    public function preferredTimezone(string $accountId, ?string $fallback = null): string
    {
        $timezone = $this->basePreferenceQuery($accountId)->value('timezone');

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return is_string($fallback) && $fallback !== ''
            ? $fallback
            : (string) config('app.timezone', 'UTC');
    }

    /**
     * @return Builder<CommunicationPreference>
     */
    public function basePreferenceQuery(string $accountId): Builder
    {
        return CommunicationPreference::query()
            ->where('recipient_type', (new User)->getMorphClass())
            ->where('recipient_id', $accountId)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type');
    }

    public function basePreference(string $accountId): ?CommunicationPreference
    {
        return $this->basePreferenceQuery($accountId)->first();
    }

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateBasePreference(string $accountId, array $attributes): int
    {
        return $this->basePreferenceQuery($accountId)->update($attributes);
    }

    /**
     * @return Builder<CommunicationDestination>
     */
    public function destinationsQuery(string $accountId): Builder
    {
        return CommunicationDestination::query()
            ->where('recipient_type', (new User)->getMorphClass())
            ->where('recipient_id', $accountId);
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
     * @return Collection<int, CommunicationDestination>
     */
    public function pushDestinations(string $accountId): Collection
    {
        return $this->destinationsQuery($accountId)
            ->where('channel', 'push')
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->get();
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
