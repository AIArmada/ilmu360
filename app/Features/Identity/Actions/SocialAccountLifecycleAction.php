<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Models\SocialAccount;

/**
 * Snapshot, remove, and recreate an account's social login links around
 * account deletion and restoration.
 */
final class SocialAccountLifecycleAction
{
    /**
     * @return array{social_accounts: list<array<string, mixed>>}
     */
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

    /**
     * @param  array<string, mixed>  $snapshot
     */
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
}
