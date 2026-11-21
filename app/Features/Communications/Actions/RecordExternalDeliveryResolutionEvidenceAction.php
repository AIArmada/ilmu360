<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationAttempt;

final readonly class RecordExternalDeliveryResolutionEvidenceAction
{
    /** @param array<string, mixed> $metadata */
    public function handle(CommunicationAttempt $attempt, array $metadata): void
    {
        $attempt->metadata = $metadata;
        $attempt->save();
    }
}
