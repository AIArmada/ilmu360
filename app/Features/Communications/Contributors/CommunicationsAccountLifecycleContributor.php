<?php

declare(strict_types=1);

namespace App\Features\Communications\Contributors;

use App\Features\Communications\Actions\ManageCommunicationsAccountLifecycleAction;
use App\Shared\Auth\AccountLifecycleContributor;

final readonly class CommunicationsAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(private ManageCommunicationsAccountLifecycleAction $communications) {}

    public function key(): string
    {
        return 'communications';
    }

    public function priority(): int
    {
        return 20;
    }

    public function snapshot(string $accountId): array
    {
        return $this->communications->snapshot($accountId);
    }

    public function detach(string $accountId): void
    {
        $this->communications->detach($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->communications->restore($accountId, $snapshot);
    }

    public function afterRestore(string $accountId): void {}
}
