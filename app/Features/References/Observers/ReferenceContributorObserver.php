<?php

declare(strict_types=1);

namespace App\Features\References\Observers;

use AIArmada\References\Models\ReferenceContributor;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\Services\ReferenceSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ReferenceContributorObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected ReferenceWorkflow $referenceWorkflow,
        protected ReferenceSearchService $referenceSearchService,
        protected PublicListingsCacheInvalidator $publicListingsCache,
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
