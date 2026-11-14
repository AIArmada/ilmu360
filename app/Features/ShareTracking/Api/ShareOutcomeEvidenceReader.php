<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use Carbon\CarbonImmutable;

/**
 * Returns aggregate, privacy-screened outcome evidence for a bounded period.
 * The values contain no subject identifiers or mutable database objects.
 */
interface ShareOutcomeEvidenceReader
{
    public function forWindow(CarbonImmutable $start, CarbonImmutable $endExclusive): ShareOutcomeEvidence;
}
