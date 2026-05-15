<?php

namespace App\Observers;

use App\Actions\References\GenerateReferenceSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Reference;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;

class ReferenceObserver
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected GenerateReferenceSlugAction $generateReferenceSlugAction,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
    ) {}

    public function updated(Reference $reference): void
    {
        if (! $reference->wasChanged('title')) {
            return;
        }

        $this->syncCurrentAndPreviousString(
            $reference->title,
            $reference->getPrevious()['title'] ?? null,
            fn (string $title): bool => $this->generateReferenceSlugAction->syncReferenceSlugsForTitle($title),
        );
    }

    public function deleted(Reference $reference): void
    {
        $this->syncSlugRedirectAction->purgeForModel($reference);
        $this->generateReferenceSlugAction->syncReferenceSlugsForTitle($reference->title);
    }
}
