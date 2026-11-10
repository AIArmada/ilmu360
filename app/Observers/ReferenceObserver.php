<?php

namespace App\Observers;

use App\Features\Directory\Api\ReferenceWorkflow;
use App\Models\Reference;
use App\Platform\Cache\PublicListingsCache;
use App\Platform\Models\Concerns\SyncsCurrentAndPreviousValues;
use App\Platform\Routing\SyncSlugRedirectAction;
use App\Support\Search\ReferenceSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ReferenceObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected ReferenceWorkflow $referenceWorkflow,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected ReferenceSearchService $referenceSearchService,
        protected PublicListingsCache $publicListingsCache,
    ) {}

    public function saved(Reference $reference): void
    {
        if (! $reference->wasRecentlyCreated && ! $reference->wasChanged()) {
            return;
        }

        $this->referenceSearchService->bustPublicSearchCache();
        $this->publicListingsCache->bustHomepageStats();
    }

    public function updated(Reference $reference): void
    {
        $titleChanged = $reference->wasChanged('title');
        $changedAttributes = array_keys($reference->getChanges());

        if (array_intersect([
            'parent_id',
            'record_kind',
            'edition_number',
            'edition_label',
            'publisher',
            'year',
            'reference_parts',
        ], $changedAttributes) !== []) {
            $this->referenceWorkflow->syncHierarchy((string) $reference->getKey(), $changedAttributes);
        }

        if ($titleChanged) {
            $this->syncCurrentAndPreviousString(
                $reference->title,
                $reference->getPrevious()['title'] ?? null,
                fn (string $title): bool => $this->referenceWorkflow->syncSlugsForTitle($title),
            );
        }
    }

    public function deleted(Reference $reference): void
    {
        $this->syncSlugRedirectAction->purgeForModel($reference);
        $this->referenceWorkflow->syncSlugsForTitle($reference->title);
        $this->referenceSearchService->bustPublicSearchCache();
        $this->publicListingsCache->bustHomepageStats();
    }
}
