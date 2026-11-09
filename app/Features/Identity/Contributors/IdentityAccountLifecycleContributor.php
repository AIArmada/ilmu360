<?php

declare(strict_types=1);

namespace App\Features\Identity\Contributors;

use App\Features\Identity\Actions\SocialAccountLifecycleAction;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Account-level identity state owned by the login layer.
 *
 * Social accounts are deleted with the account and recreated from the
 * snapshot on restore. Prayer institution preferences (`user_prayer_institutions`)
 * are intentionally not touched: they are preserved by non-deletion and
 * become live again when the user row is restored, matching the previous
 * account-deletion semantics.
 */
final readonly class IdentityAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(private SocialAccountLifecycleAction $socialAccounts) {}

    public function key(): string
    {
        return 'identity';
    }

    public function priority(): int
    {
        return 300;
    }

    public function snapshot(string $accountId): array
    {
        return $this->socialAccounts->snapshot($accountId);
    }

    public function detach(string $accountId): void
    {
        $this->socialAccounts->detach($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->socialAccounts->restore($accountId, $snapshot);
    }

    public function afterRestore(string $accountId): void {}
}
