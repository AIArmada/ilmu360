<?php

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\SuppressionResolver;
use AIArmada\Communications\Data\SuppressionDecisionData;

class AppSuppressionResolver implements SuppressionResolver
{
    public function resolve(
        ?string $recipientType,
        ?string $recipientId,
        ?string $destinationHash,
        ?string $channel,
        ?string $category,
    ): SuppressionDecisionData {
        return new SuppressionDecisionData(suppressed: false);
    }
}
