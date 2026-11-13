<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use App\Shared\ShareTracking\ShareOutcomeType;
use Carbon\CarbonInterface;

/**
 * Freeze-once writer for the committed source-attribution decision of a share
 * outcome. Later freezes with the same business key replay the first row.
 */
interface SourceAttributionReceipts
{
    public function freezeAttributed(
        ShareOutcomeType $type,
        string $businessKey,
        string $sourceTransitionId,
        string $attributionId,
        string $provenance,
        string $definitionVersion,
        CarbonInterface $occurredAt,
    ): SourceAttributionReceiptSnapshot;

    public function freezeUnattributed(
        ShareOutcomeType $type,
        string $businessKey,
        string $sourceTransitionId,
        string $provenance,
        string $definitionVersion,
        CarbonInterface $occurredAt,
    ): SourceAttributionReceiptSnapshot;
}
