<?php

declare(strict_types=1);

namespace App\Features\Ai\Contributors;

use App\Features\Ai\Actions\ManageAiUsageAccountLifecycleAction;
use App\Features\Ai\Models\AiUsageLog;
use App\Shared\Auth\AccountLifecycleContributor;

/** Preserves account AI usage history across deletion and restoration. */
final readonly class AiAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(private ManageAiUsageAccountLifecycleAction $usage) {}

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
        $this->usage->detach($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->usage->restore($accountId, $snapshot);
    }

    public function afterRestore(string $accountId): void {}
}
