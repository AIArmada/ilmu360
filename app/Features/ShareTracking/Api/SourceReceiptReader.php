<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use DateTimeImmutable;

/**
 * Read boundary over committed source-attribution receipts.
 *
 * Only the canonical share-outcome business keys cross: callers never see
 * receipt models, attribution ids or other mutable rows. The window is
 * inclusive and results are ordered by `occurred_at` then `id`, matching the
 * order the receipt table was historically read in.
 */
interface SourceReceiptReader
{
    /**
     * @return iterable<string> ordered business keys inside the inclusive window
     */
    public function keysBetween(DateTimeImmutable $from, DateTimeImmutable $to): iterable;
}
