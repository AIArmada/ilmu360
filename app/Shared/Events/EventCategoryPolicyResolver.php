<?php

declare(strict_types=1);

namespace App\Shared\Events;

interface EventCategoryPolicyResolver
{
    /** @param list<string> $termIds */
    public function requiresSpeaker(array $termIds): bool;

    /** @param list<string> $termIds */
    public function requiresPhysicalDelivery(array $termIds): bool;
}
