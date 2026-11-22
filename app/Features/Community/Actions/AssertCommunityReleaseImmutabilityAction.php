<?php

declare(strict_types=1);

namespace App\Features\Community\Actions;

use LogicException;

/** Reject modifications to published, privacy-thresholded community releases. */
final readonly class AssertCommunityReleaseImmutabilityAction
{
    public function updating(): never
    {
        throw new LogicException('Published community releases are immutable.');
    }

    public function deleting(): never
    {
        throw new LogicException('Community releases cannot be deleted.');
    }
}
