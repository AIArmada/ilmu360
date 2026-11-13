<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\NotificationChannel;
use App\Shared\Communications\AccountNotificationSettings;

final readonly class AccountNotificationSettingsAdapter implements AccountNotificationSettings
{
    public function __construct(
        private AccountNotificationPreferences $settings,
        private AccountCommunications $communications,
    ) {}

    public function stateFor(string $accountId): array
    {
        return $this->settings->stateFor($accountId);
    }

    public function save(string $accountId, array $payload): void
    {
        $this->settings->save($accountId, $payload);
    }

    public function syncProfileSettings(string $accountId): void
    {
        $this->settings->syncProfileSettings($accountId);
    }

    public function removePushDestination(string $accountId, string $destinationId): bool
    {
        $destination = $this->communications->destinationsQuery($accountId)
            ->whereKey($destinationId)
            ->where('channel', NotificationChannel::Push->value)
            ->first();

        if ($destination === null) {
            return false;
        }

        $destination->delete();

        return true;
    }
}
