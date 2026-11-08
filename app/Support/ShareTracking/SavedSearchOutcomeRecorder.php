<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Contracts\ShareTrackingContract;
use App\Enums\ShareOutcomeType;
use App\Models\User;
use App\Shared\Auth\ResourceOwner;
use App\Shared\ShareTracking\SavedSearchShareOutcomes;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final readonly class SavedSearchOutcomeRecorder implements SavedSearchShareOutcomes
{
    public function __construct(
        private ShareTrackingContract $shareTracking,
        private ReverseShareOutcomeAction $reverse,
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
            request: $request,
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
        $this->reverse->handle(
            ShareOutcomeBusinessKey::savedSearch($savedSearchId),
            'saved_search_deleted',
            $actor instanceof User ? $actor : null,
            $request,
        );
    }
}
