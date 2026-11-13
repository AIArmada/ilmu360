<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

interface AccountNotificationPreferences
{
    /**
     * @return array<string, mixed>
     */
    public function stateFor(string $accountId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(string $accountId, array $payload): array;

    public function syncProfileSettings(string $accountId): void;

    public function syncSystemEmailDestination(string $accountId): void;

    public function updateLocale(string $accountId, string $locale): void;

    public function preferredLocale(string $accountId): string;

    public function preferredTimezone(string $accountId, ?string $fallback = null): string;

    public function resolvePolicy(string $accountId, NotificationTrigger $trigger): ResolvedNotificationPolicy;
}
