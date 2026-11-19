<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use App\Shared\Auth\ResourceOwner;
use App\Shared\ShareTracking\ShareRequestContext;
use Carbon\CarbonInterface;

interface SavedSearchShareOutcomes
{
    public function recordCreated(
        string $savedSearchId,
        ResourceOwner $actor,
        ShareRequestContext $request,
        ?CarbonInterface $occurredAt = null,
    ): void;

    public function reverseDeleted(
        string $savedSearchId,
        ?ResourceOwner $actor = null,
    ): void;
}
