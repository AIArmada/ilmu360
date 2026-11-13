<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

final readonly class FollowState
{
    public function __construct(
        public bool $isFollowing,
        public int $followersCount,
    ) {}
}
