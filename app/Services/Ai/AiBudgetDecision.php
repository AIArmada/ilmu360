<?php

declare(strict_types=1);

namespace App\Services\Ai;

final readonly class AiBudgetDecision
{
    public function __construct(
        public string $decision,
        public string $reasonCode,
    ) {
        if (! in_array($decision, ['allow', 'privileged_approval', 'defer', 'deny'], true)) {
            throw new \InvalidArgumentException('Invalid AI budget decision.');
        }
    }
}
