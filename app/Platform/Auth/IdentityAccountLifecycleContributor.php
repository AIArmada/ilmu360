<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Models\SocialAccount;
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
final class IdentityAccountLifecycleContributor implements AccountLifecycleContributor
{
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
        return [
            'social_accounts' => SocialAccount::query()
                ->where('user_id', $accountId)
                ->get()
                ->map->attributesToArray()
                ->all(),
        ];
    }

    public function detach(string $accountId): void
    {
        SocialAccount::query()
            ->where('user_id', $accountId)
            ->delete();
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $records = $snapshot['social_accounts'] ?? [];

        if (! is_array($records)) {
            return;
        }

        foreach ($records as $attributes) {
            if (! is_array($attributes)) {
                continue;
            }

            $socialAccount = new SocialAccount;
            $socialAccount->timestamps = false;
            $socialAccount->forceFill($attributes);

            $existing = SocialAccount::query()->find($socialAccount->getKey());

            if ($existing instanceof SocialAccount
                && ($existing->user_id === null || (string) $existing->user_id === $accountId)) {
                $existing->delete();
            }

            $socialAccount->saveQuietly();
        }
    }

    public function afterRestore(string $accountId): void {}
}
