<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Immutable time-expression fields used to filter one public schedule leaf. */
final readonly class EventScheduleTimeFact
{
    public function __construct(
        public string $timeMode,
        public string $displayLabel,
        public string $anchorCode,
        public string $relation,
    ) {}
}
