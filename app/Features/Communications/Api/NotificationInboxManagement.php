<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

interface NotificationInboxManagement
{
    /**
     * @param  string|null  $familyValue  Public Communications family key; null returns every family.
     */
    public function pageForAccount(
        string $accountId,
        ?string $familyValue,
        string $status,
        int $perPage,
        int $page,
        bool $newestFirst = false,
    ): NotificationInboxPageSnapshot;

    public function unreadCountForAccount(string $accountId): int;

    public function markAsRead(string $accountId, string $messageId): NotificationInboxMessageSnapshot;

    public function markAllAsRead(string $accountId): int;
}
