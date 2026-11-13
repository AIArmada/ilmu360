<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

/**
 * Immutable outcome of one unfollow attempt.
 *
 * `removed` is true only when an active follow row was locked and removed by
 * this call; an idempotent replay reports false and never reverses the share
 * outcome again.
 */
final readonly class UnfollowSubjectResult
{
    public function __construct(
        public bool $removed,
    ) {}
}
