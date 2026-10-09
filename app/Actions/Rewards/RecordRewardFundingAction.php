<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Models\RewardFundingEntry;
use App\Models\RewardFundingSource;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Allocate confirmed funding from a global source to one program +
 * currency (operator setup input).
 *
 * The source row carries the confirmed total; this action draws a bounded
 * allocation from it. Concurrent allocations serialize on the source
 * row, so one deposit split across programs can never overallocate, and
 * no currency is ever summed or converted. Entries are immutable; a
 * correction is a new entry, never a rewrite. This action never moves
 * live money and never activates a program.
 */
final readonly class RecordRewardFundingAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        RewardProgram|string $program,
        string $currency,
        int $amountMinor,
        string $sourceRef,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?array $evidence = null,
        ?CarbonInterface $decidedAt = null,
    ): RewardFundingEntry {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if (! in_array($actorRole, ['staff', 'admin'], true) || $actorType !== 'user') {
            throw new InvalidArgumentException('Funding entries require a staff/admin user identity.');
        }

        $currency = RewardBudgetLedger::normalizeCurrency($currency);

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Funding amount must be a positive minor-unit amount.');
        }

        if ($sourceRef === '' || mb_strlen($sourceRef) > 191) {
            throw new InvalidArgumentException('Funding source ref must be 1..191 characters.');
        }

        $programId = $program instanceof RewardProgram ? (string) $program->getKey() : $program;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use (
            $programId, $currency, $amountMinor, $sourceRef, $actorType, $actorId, $actorRole, $evidence, $decided,
        ): RewardFundingEntry {
            $exists = RewardProgram::query()->whereKey($programId)->lockForUpdate()->first();

            if (! $exists instanceof RewardProgram) {
                throw new InvalidArgumentException('Unknown reward program.');
            }

            $source = RewardFundingSource::query()
                ->where('source_ref', $sourceRef)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if (! $source instanceof RewardFundingSource) {
                throw new InvalidArgumentException('Unknown confirmed funding source; record the source before allocating from it.');
            }

            // Ambiguous operator retry first: the same (program, ref)
            // allocation may already have landed, and its own amount is
            // inside the allocated total — gating before replaying would
            // misreport a retry as overallocation. Replay only on full
            // matching terms; a ref bound to different terms is
            // corruption, never a silent merge.
            $replay = RewardFundingEntry::query()
                ->where('program_id', $programId)
                ->where('source_ref', $sourceRef)
                ->lockForUpdate()
                ->first();

            if ($replay instanceof RewardFundingEntry) {
                if (mb_strtoupper((string) $replay->currency) === $currency
                    && (int) $replay->amount_minor === $amountMinor
                    && (string) $replay->source_id === (string) $source->getKey()
                ) {
                    return $replay;
                }

                throw new InvalidArgumentException('Funding allocation ref is already bound to different terms; supply a fresh ref.');
            }

            $allocated = (int) RewardFundingEntry::query()
                ->where('source_id', $source->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('amount_minor')
                ->sum();

            if ($allocated + $amountMinor > (int) $source->amount_minor) {
                throw new InvalidArgumentException('Funding source cannot cover this allocation; refusing overallocation.');
            }

            try {
                // Savepoint-scoped: a unique violation rolls back to the
                // savepoint so the replay re-read below still runs on
                // PostgreSQL (a bare failed insert would abort the txn).
                return DB::transaction(static fn (): RewardFundingEntry => RewardFundingEntry::query()->create([
                    'program_id' => $programId,
                    'currency' => $currency,
                    'amount_minor' => $amountMinor,
                    'source_ref' => $sourceRef,
                    'source_id' => $source->getKey(),
                    'evidence' => $evidence,
                    'funded_by_type' => $actorType,
                    'funded_by_id' => $actorId,
                    'funded_by_role' => $actorRole,
                    'funded_at' => $decided,
                ]), 1);
            } catch (QueryException $exception) {
                if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw $exception;
                }

                // Lost the insert race: whoever won holds the ref now.
                $winner = RewardFundingEntry::query()
                    ->where('program_id', $programId)
                    ->where('source_ref', $sourceRef)
                    ->lockForUpdate()
                    ->first();

                if ($winner instanceof RewardFundingEntry
                    && mb_strtoupper((string) $winner->currency) === $currency
                    && (int) $winner->amount_minor === $amountMinor
                    && (string) $winner->source_id === (string) $source->getKey()
                ) {
                    return $winner;
                }

                throw new InvalidArgumentException(
                    'Funding allocation ref is already bound to different terms; supply a fresh ref.', 0, $exception,
                );
            }
        });
    }
}
