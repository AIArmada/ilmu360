<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AiUsageLog;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Preserves the account's AI usage ledger across deletion and restoration.
 *
 * The AI capability will own usage logs once extracted. Until then this
 * contributor replaces the removed User reverse relation without exposing the
 * ledger to other features.
 */
final readonly class AiAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function key(): string
    {
        return 'ai';
    }

    public function priority(): int
    {
        return 30;
    }

    public function snapshot(string $accountId): array
    {
        return [
            'ai_usage_logs' => AiUsageLog::query()
                ->where('user_id', $accountId)
                ->get()
                ->map(static fn (AiUsageLog $log): array => $log->attributesToArray())
                ->all(),
        ];
    }

    public function detach(string $accountId): void
    {
        AiUsageLog::query()
            ->where('user_id', $accountId)
            ->get()
            ->each(static fn (AiUsageLog $log): ?bool => $log->delete());
    }

    public function restore(string $accountId, array $snapshot): void
    {
        foreach ($snapshot['ai_usage_logs'] ?? [] as $attributes) {
            if (! is_array($attributes)) {
                continue;
            }

            $model = new AiUsageLog;
            $model->timestamps = false;
            $model->forceFill($attributes);
            $model->saveQuietly();
        }
    }

    public function afterRestore(string $accountId): void {}
}
