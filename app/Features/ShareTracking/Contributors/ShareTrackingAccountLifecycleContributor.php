<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Contributors;

use App\Features\ShareTracking\Actions\AccountLifecycle\ManageShareTrackingAccountLifecycleAction;
use App\Shared\Auth\AccountLifecycleContributor;

final readonly class ShareTrackingAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(private ManageShareTrackingAccountLifecycleAction $lifecycle) {}

    public function key(): string
    {
        return 'share_tracking';
    }

    public function priority(): int
    {
        return 10;
    }

    public function snapshot(string $accountId): array
    {
        return $this->lifecycle->snapshot($accountId);
    }

    public function detach(string $accountId): void
    {
        $this->lifecycle->detach($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->lifecycle->restore($accountId, $snapshot);
    }

    public function afterRestore(string $accountId): void {}
}
