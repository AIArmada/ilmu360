<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Models\RewardFundingSource;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Record one confirmed funding source (operator setup input).
 *
 * The source is the global overallocation ceiling: programs draw bounded
 * allocations from it, and the allocations can never exceed the
 * confirmed total across every program and currency bucket. Sources are
 * immutable; a correction is a new source, never a rewrite. This action
 * never moves live money and never activates a program.
 */
final readonly class RecordRewardFundingSourceAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        string $sourceRef,
        string $currency,
        int $amountMinor,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?array $evidence = null,
        ?CarbonInterface $decidedAt = null,
    ): RewardFundingSource {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if (! in_array($actorRole, ['staff', 'admin'], true) || $actorType !== 'user') {
            throw new InvalidArgumentException('Funding sources require a staff/admin user identity.');
        }

        $currency = RewardBudgetLedger::normalizeCurrency($currency);

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Funding source amount must be a positive minor-unit amount.');
        }

        if ($sourceRef === '' || mb_strlen($sourceRef) > 191) {
            throw new InvalidArgumentException('Funding source ref must be 1..191 characters.');
        }

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use (
            $sourceRef, $currency, $amountMinor, $actorType, $actorId, $actorRole, $evidence, $decided,
        ): RewardFundingSource {
            $existing = RewardFundingSource::query()
                ->where('source_ref', $sourceRef)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof RewardFundingSource) {
                $this->assertSameTerms($existing, $amountMinor);

                return $existing;
            }

            try {
                // Nested transaction: the savepoint contains a unique
                // violation so the outer transaction survives to replay.
                return DB::transaction(static fn (): RewardFundingSource => RewardFundingSource::query()->create([
                    'source_ref' => $sourceRef,
                    'currency' => $currency,
                    'amount_minor' => $amountMinor,
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

                // A concurrent recorder won; replay on same terms, refuse
                // on conflicting ones.
                $winner = RewardFundingSource::query()
                    ->where('source_ref', $sourceRef)
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->first();

                if (! $winner instanceof RewardFundingSource) {
                    throw $exception;
                }

                $this->assertSameTerms($winner, $amountMinor);

                return $winner;
            }
        });
    }

    private function assertSameTerms(RewardFundingSource $source, int $amountMinor): void
    {
        if ((int) $source->amount_minor !== $amountMinor) {
            throw new InvalidArgumentException('Funding source is already confirmed with a different total; record a correcting source instead.');
        }
    }
}
