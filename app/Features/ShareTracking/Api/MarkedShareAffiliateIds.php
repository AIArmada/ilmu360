<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Resolves affiliate identifiers explicitly marked for ShareTracking. */
interface MarkedShareAffiliateIds
{
    /** @return list<string> */
    public function allMarkedAffiliateIds(): array;
}
