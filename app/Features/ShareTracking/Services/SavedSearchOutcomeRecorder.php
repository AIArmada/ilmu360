<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Services;

use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Features\ShareTracking\Api\ShareTrackingContract;
use App\Models\User;
use App\Shared\Auth\ResourceOwner;
use App\Shared\ShareTracking\SavedSearchShareOutcomes;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use App\Shared\ShareTracking\ShareRequestContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final readonly class SavedSearchOutcomeRecorder implements SavedSearchShareOutcomes
{
    public function __construct(
        private ShareTrackingContract $shareTracking,
        private ShareOutcomeReversals $reverse,
    ) {}

    public function recordCreated(
        string $savedSearchId,
        ResourceOwner $actor,
        Request $request,
        ?CarbonInterface $occurredAt = null,
    ): void {
        $this->shareTracking->recordOutcome(
            type: ShareOutcomeType::SavedSearchCreated,
            businessKey: ShareOutcomeBusinessKey::savedSearch($savedSearchId),
            actor: $actor instanceof User ? $actor : null,
            request: ShareRequestContext::fromRequest($request),
            metadata: [
                'saved_search_id' => $savedSearchId,
            ],
            occurredAt: $occurredAt,
        );
    }

    public function reverseDeleted(
        string $savedSearchId,
        ?Authenticatable $actor,
        Request $request,
    ): void {
        $this->reverse->reverse(
            ShareOutcomeBusinessKey::savedSearch($savedSearchId),
            'saved_search_deleted',
            $actor instanceof User ? (string) $actor->getKey() : null,
        );
    }
}
