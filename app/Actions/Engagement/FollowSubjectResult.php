<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use AIArmada\Engagement\Models\Follow;

/**
 * Immutable outcome of one follow attempt.
 *
 * `created` reports whether this call created a new follow row; a replayed
 * follow or a reactivation returns the existing/restored row with
 * `created = false` and never records another share outcome.
 */
final readonly class FollowSubjectResult
{
    public function __construct(
        public Follow $follow,
        public bool $created,
    ) {}
}
