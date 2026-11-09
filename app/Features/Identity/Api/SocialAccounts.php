<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

interface SocialAccounts
{
    /**
     * Resolve a linked social login by its provider identity.
     */
    public function findByProvider(string $provider, string $providerId): ?SocialAccountSnapshot;

    /**
     * Link or refresh the account's provider identity.
     */
    public function link(string $accountId, string $provider, string $providerId, ?string $avatarUrl): SocialAccountSnapshot;

    /**
     * The most recently refreshed social avatar for the account.
     */
    public function avatarUrl(string $accountId): ?string;
}
