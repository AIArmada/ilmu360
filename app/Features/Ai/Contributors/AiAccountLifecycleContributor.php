<?php

declare(strict_types=1);

namespace App\Features\Ai\Contributors;

use App\Features\Ai\Models\AiUsageLog;
use App\Shared\Auth\AccountLifecycleContributor;

/** Preserves account AI usage history across deletion and restoration. */
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
