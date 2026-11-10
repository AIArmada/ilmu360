<?php

namespace App\Observers;

use AIArmada\References\Models\ReferenceContributor;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Platform\Cache\PublicListingsCache;
use App\Support\Search\ReferenceSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ReferenceContributorObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected ReferenceWorkflow $referenceWorkflow,
        protected ReferenceSearchService $referenceSearchService,
        protected PublicListingsCache $publicListingsCache,
    ) {}

    public function saved(ReferenceContributor $contributor): void
    {
        $this->refreshFamily($contributor);
    }

    public function deleted(ReferenceContributor $contributor): void
    {
        $this->refreshFamily($contributor);
    }

    private function refreshFamily(ReferenceContributor $contributor): void
    {
        $this->referenceSearchService->bustPublicSearchCache();
        $this->publicListingsCache->bustHomepageStats();

        $this->referenceWorkflow->reindexSearchFamily((string) $contributor->reference_id);
    }
}
