<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\NotificationChannel;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use App\Shared\Communications\AccountNotificationSettings;

final readonly class AccountNotificationSettingsAdapter implements AccountNotificationSettings
{
    public function __construct(
        private NotificationSettingsManager $settings,
        private AccountCommunications $communications,
    ) {}

    public function stateFor(string $accountId): array
    {
        return $this->settings->stateFor($this->user($accountId));
    }

    public function save(string $accountId, array $payload): void
    {
        $this->settings->save($this->user($accountId), $payload);
    }

    public function syncProfileSettings(string $accountId): void
    {
        $this->settings->syncProfileSettings($this->user($accountId));
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

    private function user(string $accountId): User
    {
        return User::query()->findOrFail($accountId);
    }
}
