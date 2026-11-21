<?php

declare(strict_types=1);

namespace App\Features\Ai\Actions;

use App\Features\Ai\Models\AiUsageLog;

/**
 * Owns AI usage history detach and restore writes during account deletion.
 */
final readonly class ManageAiUsageAccountLifecycleAction
{
    public function detach(string $accountId): void
    {
        AiUsageLog::query()
            ->where('user_id', $accountId)
            ->get()
            ->each(static fn (AiUsageLog $log): ?bool => $log->delete());
    }

    /** @param array<string, mixed> $snapshot */
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

}
