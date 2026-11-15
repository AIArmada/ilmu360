<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Reads whether an affiliate has financial records outside conversions. */
interface FinancialObligationReader
{
    public function hasNonConversionFinancialRecords(string $affiliateId): bool;
}
