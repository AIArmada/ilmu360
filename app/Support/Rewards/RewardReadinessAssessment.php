<?php

declare(strict_types=1);

namespace App\Support\Rewards;

final readonly class RewardReadinessAssessment
{
    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $notes
     */
    public function __construct(
        public bool $ready,
        public array $blockers = [],
        public array $notes = [],
    ) {}
}
