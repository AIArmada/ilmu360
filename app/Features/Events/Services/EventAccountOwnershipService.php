<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Actions\Account\CaptureEventAccountOwnershipAction;
use App\Features\Events\Actions\Account\DetachEventAccountOwnershipAction;
use App\Features\Events\Actions\Account\RestoreEventAccountOwnershipAction;
use App\Features\Events\Api\EventAccountOwnership;

final readonly class EventAccountOwnershipService implements EventAccountOwnership
{
    public function __construct(
        private CaptureEventAccountOwnershipAction $capture,
        private DetachEventAccountOwnershipAction $detachLinks,
        private RestoreEventAccountOwnershipAction $restoreLinks,
    ) {}

    public function snapshot(string $accountId): array
    {
        return $this->capture->handle($accountId);
    }

    public function detach(string $accountId): void
    {
        $this->detachLinks->handle($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->restoreLinks->handle($accountId, $snapshot);
    }
}
