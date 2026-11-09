<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\SocialAccountSnapshot;
use App\Features\Identity\Models\SocialAccount;

/**
 * Link or refresh one provider identity for an account.
 */
final class LinkSocialAccountAction
{
    public function handle(string $accountId, string $provider, string $providerId, ?string $avatarUrl): SocialAccountSnapshot
    {
        $account = SocialAccount::query()->updateOrCreate([
            'user_id' => $accountId,
            'provider' => $provider,
        ], [
            'provider_id' => $providerId,
            'avatar_url' => $avatarUrl,
        ]);

        return new SocialAccountSnapshot(
            id: (string) $account->getKey(),
            accountId: (string) $account->user_id,
            provider: (string) $account->provider,
            providerId: (string) $account->provider_id,
            avatarUrl: $account->avatar_url,
        );
    }
}
