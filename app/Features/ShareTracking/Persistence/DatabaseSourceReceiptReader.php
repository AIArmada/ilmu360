<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Persistence;

use App\Features\ShareTracking\Api\SourceReceiptReader;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use DateTimeImmutable;
use Generator;

/**
 * Lazy ordered read over the source-attribution receipt table.
 *
 * The query starts only when the returned generator is iterated, so the
 * caller's decision window is evaluated against the rows visible at that
 * moment; the cursor keeps memory bounded for unbounded lookback windows.
 */
final class DatabaseSourceReceiptReader implements SourceReceiptReader
{
    /**
     * @return Generator<int, string>
     */
    public function keysBetween(DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        $receipts = ShareTrackingModel::sourceReceiptQuery()
            ->where('namespace', ShareOutcomeBusinessKey::NAMESPACE)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->cursor();

        foreach ($receipts as $receipt) {
            $businessKey = $receipt->getAttribute('business_key');

            if (! is_string($businessKey) || $businessKey === '') {
                continue;
            }

            yield $businessKey;
        }
    }
}
