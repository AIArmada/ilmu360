<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions;

use App\Features\ShareTracking\Models\ShareCohortProjection;
use LogicException;

/** Preserve immutable committed-share cohort facts and forward-only stages. */
final readonly class AssertShareCohortProjectionLifecycleAction
{
    public function updating(ShareCohortProjection $projection): void
    {
        foreach (['business_key', 'outcome', 'cohort_week', 'occurred_at'] as $field) {
            if ($projection->isDirty($field)) {
                throw new LogicException("Share cohort projection [{$field}] is frozen.");
            }
        }

        if ($projection->isDirty('stage')
            && ($projection->getOriginal('stage') !== ShareCohortProjection::STAGE_OBSERVED || $projection->stage !== ShareCohortProjection::STAGE_PARTICIPATED)
        ) {
            throw new LogicException('Share cohort projection stages move observed -> participated only.');
        }
    }

    public function deleting(): never
    {
        throw new LogicException('Share cohort projections cannot be deleted.');
    }
}
