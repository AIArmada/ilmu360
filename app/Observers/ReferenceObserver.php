<?php

namespace App\Observers;

use App\Actions\References\GenerateReferenceSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Reference;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicListingsCache;
use App\Support\Search\ReferenceSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ReferenceObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected GenerateReferenceSlugAction $generateReferenceSlugAction,
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
        $hierarchyChanged = $reference->wasChanged(['parent_id', 'record_kind', 'edition_number', 'edition_label', 'publisher', 'year', 'reference_parts']);

        if ($reference->wasChanged(['record_kind', 'parent_id']) && ! $reference->isRootReference()) {
            $reference->authorLinks()->delete();
            $reference->unsetRelation('authorLinks');
            $reference->unsetRelation('authors');

            if ($reference->shouldBeSearchable()) {
                $reference->searchable();
            } else {
                $reference->unsearchable();
            }
        }

        if ($hierarchyChanged) {
            $this->generateReferenceSlugAction->syncReferenceSlug($reference);
            foreach ($reference->childReferences()->with('parentReference.parentReference')->get() as $child) {
                $this->generateReferenceSlugAction->syncReferenceSlug($child);
                if ($child->shouldBeSearchable()) {
                    $child->searchable();
                } else {
                    $child->unsearchable();
                }
            }
        }

        if ($titleChanged) {
            $this->syncCurrentAndPreviousString(
                $reference->title,
                $reference->getPrevious()['title'] ?? null,
                fn (string $title): bool => $this->generateReferenceSlugAction->syncReferenceSlugsForTitle($title),
            );
        }
    }

    public function deleted(Reference $reference): void
    {
        $this->syncSlugRedirectAction->purgeForModel($reference);
        $this->generateReferenceSlugAction->syncReferenceSlugsForTitle($reference->title);
        $this->referenceSearchService->bustPublicSearchCache();
        $this->publicListingsCache->bustHomepageStats();
    }
}
