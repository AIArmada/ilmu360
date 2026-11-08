<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

use App\Shared\Auth\ResourceOwner;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

interface SavedSearchShareOutcomes
{
    public function recordCreated(
        string $savedSearchId,
        ResourceOwner $actor,
        Request $request,
        ?CarbonInterface $occurredAt = null,
    ): void;

    public function reverseDeleted(
        string $savedSearchId,
        ?Authenticatable $actor,
        Request $request,
    ): void;
}
