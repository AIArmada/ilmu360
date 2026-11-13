<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Persistence;

use App\Features\ShareTracking\Api\OutcomeCorrections;
use App\Features\ShareTracking\Api\OutcomeCorrectionSnapshot;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent persistence for share-outcome corrections.
 *
 * The nested transaction plus unique business key keeps the first committed
 * correction authoritative: a duplicate or concurrent insert replays that row
 * rather than rewriting its conversion, reason, actor or occurrence time.
 * Creation stays Eloquent so the correction observer dispatches its jobs.
 */
final class DatabaseOutcomeCorrections implements OutcomeCorrections
{
    public function ensure(
        string $businessKey,
        string $conversionId,
        string $reason,
        ?string $actorType = null,
        ?string $actorId = null,
    ): OutcomeCorrectionSnapshot {
        try {
            $correction = DB::transaction(static fn (): Model => ShareTrackingModel::outcomeCorrectionQuery()->create([
                'conversion_id' => $conversionId,
                'business_key' => $businessKey,
                'reason' => $reason,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'occurred_at' => now(),
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $correction = ShareTrackingModel::outcomeCorrectionQuery()
                ->where('business_key', $businessKey)
                ->firstOrFail();
        }

        return $this->snapshot($correction);
    }

    private function snapshot(Model $correction): OutcomeCorrectionSnapshot
    {
        return new OutcomeCorrectionSnapshot(
            id: (string) $correction->getKey(),
            conversionId: (string) $correction->getAttribute('conversion_id'),
            businessKey: (string) $correction->getAttribute('business_key'),
            reason: (string) $correction->getAttribute('reason'),
            actorType: $this->nullableString($correction->getAttribute('actor_type')),
            actorId: $this->nullableString($correction->getAttribute('actor_id')),
            occurredAt: $this->timestamp($correction->getAttribute('occurred_at')),
            createdAt: $this->timestamp($correction->getAttribute('created_at')),
            updatedAt: $this->timestamp($correction->getAttribute('updated_at')),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function timestamp(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : (string) $value;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }
}
