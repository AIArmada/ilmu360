<?php

namespace App\Observers;

use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Institution;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use App\Support\Search\InstitutionSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class InstitutionObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
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
                fn (string $name): bool => $this->generateInstitutionSlugAction->syncInstitutionSlugsForName($name, $exceptInstitutionId),
            );
        }

        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpInstitution();
        $this->institutionSearchService->bustPublicSearchCache();
    }

    public function deleted(Institution $institution): void
    {
        // Bridge rows are removed synchronously inside Institution::delete();
        // this after-commit observer keeps derived side effects only.
        $this->syncSlugRedirectAction->purgeForModel($institution);
        $this->generateInstitutionSlugAction->syncInstitutionSlugsForName($institution->name);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpInstitution();
        $this->institutionSearchService->bustPublicSearchCache();
    }
}
