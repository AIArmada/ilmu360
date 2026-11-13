<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

final readonly class EventEngagementState
{
    public function __construct(
        public bool $isSaved,
        public bool $isGoing,
        public int $savesCount,
        public int $goingCount,
    ) {}
}
