<?php

namespace App\Observers;

use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use App\Models\Institution;
use App\Platform\Models\Concerns\SyncsCurrentAndPreviousValues;
use App\Services\PublicListingsCache;
use App\Support\Search\InstitutionSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class InstitutionObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected InstitutionWorkflow $institutionWorkflow,
        protected SlugRedirectCommands $slugRedirectCommands,
        protected InstitutionListingCacheVersion $institutionCacheVersion,
        protected PublicListingsCache $publicListingsCache,
        protected InstitutionSearchService $institutionSearchService,
    ) {}

    public function saved(Institution $institution): void
    {
        if (! $institution->wasRecentlyCreated && ! $institution->wasChanged()) {
            return;
        }

        if ($institution->wasRecentlyCreated || $institution->wasChanged('name')) {
            // A slug explicitly changed in the same save wins over
            // name-derived regeneration for this row; same-name peers still
            // re-stabilize around it.
            $exceptInstitutionId = ! $institution->wasRecentlyCreated && $institution->wasChanged('slug')
                ? (string) $institution->getKey()
                : null;

            $this->syncCurrentAndPreviousString(
                $institution->name,
                $institution->wasChanged('name') ? ($institution->getPrevious()['name'] ?? null) : null,
                fn (string $name): bool => $this->institutionWorkflow->syncSlugsForName($name, $exceptInstitutionId),
            );
        }

        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->institutionCacheVersion->bump();
        $this->institutionSearchService->bustPublicSearchCache();
    }

    public function deleted(Institution $institution): void
    {
        // Bridge rows are removed synchronously inside Institution::delete();
        // this after-commit observer keeps derived side effects only.
        $this->slugRedirectCommands->purge($institution->getMorphClass(), (string) $institution->getKey());
        $this->institutionWorkflow->syncSlugsForName($institution->name);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->institutionCacheVersion->bump();
        $this->institutionSearchService->bustPublicSearchCache();
    }
}
