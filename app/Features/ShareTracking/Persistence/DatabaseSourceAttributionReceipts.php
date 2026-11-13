<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Persistence;

use App\Features\ShareTracking\Api\SourceAttributionReceiptSnapshot;
use App\Features\ShareTracking\Api\SourceAttributionReceipts;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Freeze-once persistence for share source-attribution receipts.
 *
 * The nested transaction plus unique business key keeps the first committed
 * decision authoritative: a concurrent or later freeze replays that row rather
 * than overwriting its attribution.
 */
final class DatabaseSourceAttributionReceipts implements SourceAttributionReceipts
{
    public function freezeAttributed(
        ShareOutcomeType $type,
        string $businessKey,
        string $sourceTransitionId,
        string $attributionId,
        string $provenance,
        string $definitionVersion,
        CarbonInterface $occurredAt,
    ): SourceAttributionReceiptSnapshot {
        return $this->freeze(
            type: $type,
            businessKey: $businessKey,
            sourceTransitionId: $sourceTransitionId,
            attributionId: $attributionId,
            provenance: $provenance,
            definitionVersion: $definitionVersion,
            occurredAt: $occurredAt,
        );
    }

    public function freezeUnattributed(
        ShareOutcomeType $type,
        string $businessKey,
        string $sourceTransitionId,
        string $provenance,
        string $definitionVersion,
        CarbonInterface $occurredAt,
    ): SourceAttributionReceiptSnapshot {
        return $this->freeze(
            type: $type,
            businessKey: $businessKey,
            sourceTransitionId: $sourceTransitionId,
            attributionId: null,
            provenance: $provenance,
            definitionVersion: $definitionVersion,
            occurredAt: $occurredAt,
        );
    }

    private function freeze(
        ShareOutcomeType $type,
        string $businessKey,
        string $sourceTransitionId,
        ?string $attributionId,
        string $provenance,
        string $definitionVersion,
        CarbonInterface $occurredAt,
    ): SourceAttributionReceiptSnapshot {
        try {
            $receipt = DB::transaction(static fn (): Model => ShareTrackingModel::sourceReceiptQuery()->create([
                'namespace' => ShareOutcomeBusinessKey::NAMESPACE,
                'outcome_type' => $type->value,
                'source_transition_id' => $sourceTransitionId,
                'business_key' => $businessKey,
                'attribution_id' => $attributionId,
                'provenance' => $provenance,
                'definition_version' => $definitionVersion,
                'occurred_at' => $occurredAt,
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $receipt = ShareTrackingModel::sourceReceiptQuery()
                ->where('business_key', $businessKey)
                ->firstOrFail();
        }

        return $this->snapshot($receipt);
    }

    private function snapshot(Model $receipt): SourceAttributionReceiptSnapshot
    {
        $occurredAt = $receipt->getAttribute('occurred_at');
        $attributionId = $receipt->getAttribute('attribution_id');

        return new SourceAttributionReceiptSnapshot(
            id: (string) $receipt->getKey(),
            businessKey: (string) $receipt->getAttribute('business_key'),
            attributionId: is_string($attributionId) ? $attributionId : null,
            outcomeType: (string) $receipt->getAttribute('outcome_type'),
            sourceTransitionId: (string) $receipt->getAttribute('source_transition_id'),
            provenance: (string) $receipt->getAttribute('provenance'),
            definitionVersion: (string) $receipt->getAttribute('definition_version'),
            occurredAt: $occurredAt instanceof CarbonInterface ? $occurredAt->toIso8601String() : (string) $occurredAt,
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }
}
