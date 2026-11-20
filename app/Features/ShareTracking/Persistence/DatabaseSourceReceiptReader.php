<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Persistence;

use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\SourceAttributionReceiptSnapshot;
use App\Features\ShareTracking\Api\SourceReceiptReader;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Generator;
use Illuminate\Database\Eloquent\Model;

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
        $receipts = ShareTrackingModel::query('share_source_attribution_receipt')
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

    public function findByBusinessKey(string $businessKey): ?SourceAttributionReceiptSnapshot
    {
        $receipt = ShareTrackingModel::query('share_source_attribution_receipt')
            ->where('business_key', $businessKey)
            ->first();

        if (! $receipt instanceof Model) {
            return null;
        }

        $attributionId = $receipt->getAttribute('attribution_id');

        return new SourceAttributionReceiptSnapshot(
            id: (string) $receipt->getKey(),
            businessKey: (string) $receipt->getAttribute('business_key'),
            attributionId: is_string($attributionId) ? $attributionId : null,
            outcomeType: (string) $receipt->getAttribute('outcome_type'),
            sourceTransitionId: (string) $receipt->getAttribute('source_transition_id'),
            provenance: (string) $receipt->getAttribute('provenance'),
            definitionVersion: (string) $receipt->getAttribute('definition_version'),
            occurredAt: $this->timestamp($receipt->getAttribute('occurred_at')),
        );
    }

    public function hasForSourceTransitions(string $outcomeType, array $sourceTransitionIds): bool
    {
        if ($sourceTransitionIds === []) {
            return false;
        }

        return ShareTrackingModel::query('share_source_attribution_receipt')
            ->where('namespace', ShareOutcomeBusinessKey::NAMESPACE)
            ->where('outcome_type', $outcomeType)
            ->whereIn('source_transition_id', $sourceTransitionIds)
            ->exists();
    }

    private function timestamp(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : (string) $value;
    }
}
