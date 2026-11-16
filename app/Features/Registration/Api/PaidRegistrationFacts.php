<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Read-only payment facts for callers that must distinguish purchasers from
 * participants without querying Registration's tables directly.
 */
interface PaidRegistrationFacts
{
    /**
     * @param  list<string>  $registrationIds
     * @return list<string>
     */
    public function paidRegistrationIds(array $registrationIds): array;
}
