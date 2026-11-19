<?php

namespace App\Features\References\Observers;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\Models\Reference;
use App\Features\References\Services\ReferenceSearchService;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use App\Platform\Models\Concerns\SyncsCurrentAndPreviousValues;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ReferenceObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected ReferenceWorkflow $referenceWorkflow,
        protected SlugRedirectCommands $slugRedirectCommands,
        protected ReferenceSearchService $referenceSearchService,
        protected PublicListingsCacheInvalidator $publicListingsCache,
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
        $this->slugRedirectCommands->purge($reference->getMorphClass(), (string) $reference->getKey());
        $this->referenceWorkflow->syncSlugsForTitle($reference->title);
        $this->referenceSearchService->bustPublicSearchCache();
        $this->publicListingsCache->bustHomepageStats();
    }
}
