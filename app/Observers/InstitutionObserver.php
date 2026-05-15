<?php

namespace App\Observers;

use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Institution;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use App\Support\Search\InstitutionSearchService;

class InstitutionObserver
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
        $this->syncCurrentAndPreviousString(
            $institution->name,
            $institution->wasChanged('name') ? ($institution->getPrevious()['name'] ?? null) : null,
            fn (string $name): bool => $this->generateInstitutionSlugAction->syncInstitutionSlugsForName($name),
        );

        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpInstitution();
        $this->institutionSearchService->bustPublicSearchCache();
    }

    public function deleted(Institution $institution): void
    {
        $this->syncSlugRedirectAction->purgeForModel($institution);
        $this->generateInstitutionSlugAction->syncInstitutionSlugsForName($institution->name);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpInstitution();
        $this->institutionSearchService->bustPublicSearchCache();
    }
}
