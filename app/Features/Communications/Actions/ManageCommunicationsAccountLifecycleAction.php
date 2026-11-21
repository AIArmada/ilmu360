<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use AIArmada\Communications\Models\NotificationInbox;
use App\Shared\Auth\UserMorphType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Preserves account communication preferences, destinations and inboxes
 * across deletion and restoration. Scoped family and trigger rules remain
 * untouched when the base preference is detached.
 */
final readonly class ManageCommunicationsAccountLifecycleAction
{
    public function __construct(
        private UserMorphType $userMorphType,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(string $accountId): array
    {
        return [
            'notification_setting' => $this->basePreference($accountId)?->attributesToArray(),
            'notification_destinations' => CommunicationDestination::query()
                ->where('recipient_type', $this->userMorphClass())
                ->where('recipient_id', $accountId)
                ->get()
                ->map(static fn (CommunicationDestination $destination): array => $destination->attributesToArray())
                ->all(),
            'notification_inboxes' => NotificationInbox::query()
                ->where('recipient_type', $this->userMorphClass())
                ->where('recipient_id', $accountId)
                ->get()
                ->map(static fn (NotificationInbox $inbox): array => $inbox->attributesToArray())
                ->all(),
        ];
    }

    public function detach(string $accountId): void
    {
        $this->basePreferenceQuery($accountId)->delete();

        CommunicationDestination::query()
            ->where('recipient_type', $this->userMorphClass())
            ->where('recipient_id', $accountId)
            ->delete();

        NotificationInbox::query()
            ->where('recipient_type', $this->userMorphClass())
            ->where('recipient_id', $accountId)
            ->delete();
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(string $accountId, array $snapshot): void
    {
        $this->restorePreference($snapshot['notification_setting'] ?? null);

        foreach ($snapshot['notification_destinations'] ?? [] as $attributes) {
            $this->restoreDestination($attributes);
        }

        foreach ($snapshot['notification_inboxes'] ?? [] as $attributes) {
            $this->restoreInbox($attributes);
        }
    }

    private function basePreference(string $accountId): ?CommunicationPreference
    {
        return $this->basePreferenceQuery($accountId)->first();
    }

    /**
     * @return Builder<CommunicationPreference>
     */
    private function basePreferenceQuery(string $accountId): Builder
    {
        return CommunicationPreference::query()
            ->where('recipient_type', $this->userMorphClass())
            ->where('recipient_id', $accountId)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type');
    }

    private function restorePreference(mixed $attributes): void
    {
        if (! is_array($attributes)) {
            return;
        }

        $preference = new CommunicationPreference;
        $preference->timestamps = false;
        $preference->forceFill($attributes);
        $preference->saveQuietly();
    }

    private function restoreDestination(mixed $attributes): void
    {
        if (! is_array($attributes)) {
            return;
        }

        $destination = new CommunicationDestination;
        $destination->timestamps = false;
        $destination->forceFill($attributes);
        $destination->saveQuietly();
    }

    private function restoreInbox(mixed $attributes): void
    {
        if (! is_array($attributes)) {
            return;
        }

        $inbox = new NotificationInbox;
        $inbox->timestamps = false;
        $inbox->forceFill($attributes);
        $inbox->saveQuietly();
    }

    private function userMorphClass(): string
    {
        return $this->userMorphType->morphClass();
    }
}
