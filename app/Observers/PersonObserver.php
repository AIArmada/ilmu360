<?php

namespace App\Observers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\References\Models\ReferenceContributor;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Models\Person;
use App\Models\Reference;
use App\Platform\Cache\PublicDirectoryCacheVersion;
use App\Platform\Cache\PublicListingsCache;
use App\Platform\Models\Concerns\SyncsCurrentAndPreviousValues;
use App\Platform\Routing\SyncSlugRedirectAction;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PersonObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected EventAuthoring $eventAuthoring,
        protected PersonWorkflow $personWorkflow,
        protected ReferenceWorkflow $referenceWorkflow,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        protected PublicListingsCache $publicListingsCache,
        protected PersonSearchService $personSearchService,
        protected ReferenceSearchService $referenceSearchService,
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
        $this->publicDirectoryCacheVersion->bumpPerson();
    }

    public function deleted(Person $person): void
    {
        $this->syncSlugRedirectAction->purgeForModel($person);
        $this->personWorkflow->syncSlugsForName($person->name);
        $this->eventAuthoring->syncEventSlugsForPersonId((string) $person->getKey());
        $this->eventAuthoring->syncEventSlugsForPersonName($person->name);
        $this->personSearchService->purgePersonRecord($person);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpPerson();
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

        $this->referenceSearchService->bustPublicSearchCache();

        $rootIds = [];

        foreach (Reference::query()->whereKey($referenceIds)->get(['id', 'parent_id', 'record_kind']) as $reference) {
            $rootIds[] = $reference->isRootReference()
                ? (string) $reference->getKey()
                : ($reference->familyRootId() ?? (string) $reference->getKey());
        }

        foreach (array_values(array_unique($rootIds)) as $rootId) {
            $this->referenceWorkflow->reindexSearchFamily($rootId);
        }
    }
}
