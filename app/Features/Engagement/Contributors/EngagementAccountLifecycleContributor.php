<?php

declare(strict_types=1);

namespace App\Features\Engagement\Contributors;

use App\Features\Engagement\Actions\ManageEngagementAccountLifecycleAction;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Wires the account lifecycle coordinator to the Engagement-owned Action.
 */
final readonly class EngagementAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(private ManageEngagementAccountLifecycleAction $engagement) {}

    public function key(): string
    {
        return 'engagement';
    }

    public function priority(): int
    {
        return 40;
    }

    public function snapshot(string $accountId): array
    {
        return $this->engagement->snapshot($accountId);
    }

    public function detach(string $accountId): void
    {
        $this->engagement->detach($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->engagement->restore($accountId, $snapshot);
    }

    public function afterRestore(string $accountId): void {}
}
