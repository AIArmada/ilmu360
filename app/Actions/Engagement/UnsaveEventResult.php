<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

/**
 * Immutable outcome of one remove-save attempt.
 *
 * `removed` is true only when an active bookmark row was locked and removed by
 * this call; an idempotent replay reports false and never reverses the share
 * outcome again.
 */
final readonly class UnsaveEventResult
{
    public function __construct(
        public bool $removed,
    ) {}
}
