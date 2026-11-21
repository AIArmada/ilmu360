<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions\Projection;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\References\Models\ReferenceContributor;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Services\PersonSearchService;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Api\ReferenceSearchCache;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use App\Platform\Models\Concerns\SyncsCurrentAndPreviousValues;

final readonly class SyncPersonDirectoryEffectsAction
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected EventAuthoring $eventAuthoring,
        protected PersonWorkflow $personWorkflow,
        protected ReferenceWorkflow $referenceWorkflow,
        protected SlugRedirectCommands $slugRedirectCommands,
        protected PersonListingCacheVersion $personCacheVersion,
        protected PublicListingsCacheInvalidator $publicListingsCache,
        protected PersonSearchService $personSearchService,
        protected ReferenceSearchCache $referenceSearchCache,
    ) {}

    public function saved(Person $person): void
    {
        if (! $person->wasRecentlyCreated && ! $person->wasChanged()) {
            return;
        }

        $searchableNameChanged = $person->wasRecentlyCreated || $person->wasChanged([
            'name',
            'middle_name',
            'family_name',
        ]);

        if ($searchableNameChanged) {
            $this->personSearchService->syncPersonRecord($person);

            OwnerContext::withOwner(null, function () use ($person): void {
                $this->syncCurrentAndPreviousString(
                    $person->name,
                    $person->wasChanged('name') ? ($person->getPrevious()['name'] ?? null) : null,
                    fn (string $name): bool => $this->personWorkflow->syncSlugsForName($name),
                    fn (string $name): bool => $this->eventAuthoring->syncEventSlugsForPersonName($name),
                );
            });
        } elseif ($person->wasChanged('status')) {
            $this->personSearchService->bustPublicSearchCache();
        }

        if ($person->wasRecentlyCreated || $person->wasChanged(['name', 'middle_name', 'family_name', 'slug'])) {
            $this->reindexAuthoredReferences($person);
        }

        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->personCacheVersion->bump();
    }

    public function deleted(Person $person): void
    {
        $this->slugRedirectCommands->purge($person->getMorphClass(), (string) $person->getKey());
        $this->personWorkflow->syncSlugsForName($person->name);
        $this->eventAuthoring->syncEventSlugsForPersonId((string) $person->getKey());
        $this->eventAuthoring->syncEventSlugsForPersonName($person->name);
        $this->personSearchService->purgePersonRecord($person);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->personCacheVersion->bump();
    }

    private function reindexAuthoredReferences(Person $person): void
    {
        $referenceIds = ReferenceContributor::query()
            ->where('contributor_type', $person->getMorphClass())
            ->where('contributor_id', $person->getKey())
            ->distinct()
            ->pluck('reference_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($referenceIds === []) {
            return;
        }

        $this->referenceSearchCache->bumpPublicSearchVersion();

        foreach (app(ReferenceQueries::class)->familyRootIds($referenceIds) as $rootId) {
            $this->referenceWorkflow->reindexSearchFamily($rootId);
        }
    }
}
