<?php

declare(strict_types=1);

namespace App\Features\References\Observers;

use App\Features\References\Actions\Projection\ReindexReferenceContributorEffectsAction;
use AIArmada\References\Models\ReferenceContributor;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Framework observer adapter; the feature Action owns the behavior. */
final readonly class ReferenceContributorObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private ReindexReferenceContributorEffectsAction $effects) {}

    public function saved(ReferenceContributor $contributor): void
    {
        $this->effects->saved($contributor);
    }

    public function deleted(ReferenceContributor $contributor): void
    {
        $this->effects->deleted($contributor);
    }
}
