<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use App\Features\Identity\Actions\LinkSocialAccountAction;
use App\Features\Identity\Api\SocialAccounts;
use App\Features\Identity\Api\SocialAccountSnapshot;
use App\Features\Identity\Models\SocialAccount;

final readonly class SocialAccountsService implements SocialAccounts
{
    public function __construct(private LinkSocialAccountAction $linkSocialAccount) {}

    public function findByProvider(string $provider, string $providerId): ?SocialAccountSnapshot
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        return $account instanceof SocialAccount ? $this->snapshot($account) : null;
    }

    public function link(string $accountId, string $provider, string $providerId, ?string $avatarUrl): SocialAccountSnapshot
    {
        return $this->linkSocialAccount->handle($accountId, $provider, $providerId, $avatarUrl);
    }

    public function avatarUrl(string $accountId): ?string
    {
        $avatarUrl = SocialAccount::query()
            ->where('user_id', $accountId)
            ->whereNotNull('avatar_url')
            ->latest('updated_at')
            ->value('avatar_url');

        return is_string($avatarUrl) && $avatarUrl !== '' ? $avatarUrl : null;
    }

    private function snapshot(SocialAccount $account): SocialAccountSnapshot
    {
        return new SocialAccountSnapshot(
            id: (string) $account->getKey(),
            accountId: (string) $account->user_id,
            provider: (string) $account->provider,
            providerId: (string) $account->provider_id,
            avatarUrl: $account->avatar_url,
        );
    }
}
