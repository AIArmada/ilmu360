<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBudgetMovementKind;
use App\Enums\Rewards\RewardRecoveryStatus;
use App\Models\RewardAward;
use App\Models\RewardBudgetMovement;
use App\Models\RewardRecovery;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Settle a recovery case on confirmed recovery evidence.
 *
 * Settlement requires proof that value actually returned (bank receipt,
 * returned goods, signed writeoff-equivalent acceptance — recorded in
 * evidence, never assumed). A settled cash recovery appends the budget
 * recover movement: the ONLY path that reduces net spent and replenishes
 * available budget. Settling never debits a bank, restores spent points
 * silently, or invents negative balances; point recoveries close the
 * case and leave ledger restoration to explicit linked refunds.
 */
final readonly class SettleRewardRecoveryAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $recoveryEvidence
     */
    public function handle(
        RewardRecovery|string $recovery,
        array $recoveryEvidence,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $decidedAt = null,
    ): RewardRecovery {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Recovery settlement requires a staff/admin user identity.');
        }

        $proof = mb_trim((string) ($recoveryEvidence['recovery_proof'] ?? ''));

        if ($proof === '' || mb_strlen($proof) > 191) {
            throw new InvalidArgumentException('Settlement requires recovery_proof evidence of 1..191 characters.');
        }

        $recoveryId = $recovery instanceof RewardRecovery ? (string) $recovery->getKey() : $recovery;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($recoveryId, $recoveryEvidence, $actorType, $actorId, $actorRole, $decided): RewardRecovery {
            $locked = RewardRecovery::query()->whereKey($recoveryId)->lockForUpdate()->first();

            if (! $locked instanceof RewardRecovery) {
                throw new InvalidArgumentException('Unknown reward recovery.');
            }

            if ($locked->status !== RewardRecoveryStatus::Open) {
                throw new InvalidArgumentException('Only open recoveries can be settled.');
            }

            if ($locked->amount_minor !== null && $locked->currency !== null) {
                $award = RewardAward::query()->whereKey((string) $locked->award_id)->lockForUpdate()->firstOrFail();
                $programId = (string) $award->program_id;
                $currency = RewardBudgetLedger::normalizeCurrency((string) $locked->currency);
                $amount = (int) $locked->amount_minor;

                // Every budget writer holds the common funding lock and
                // proves the legs; a recovery can only return value the
                // award actually spent, never mint budget.
                $funded = RewardBudgetLedger::lockFunding($programId, $currency);
                RewardBudgetLedger::assertNonNegativeLegs(
                    RewardBudgetLedger::lockedBalances($programId, $currency, $funded)
                );

                $spent = (int) RewardBudgetMovement::query()
                    ->where('program_id', $programId)
                    ->where('currency', $currency)
                    ->where('award_id', (string) $locked->award_id)
                    ->where('kind', RewardBudgetMovementKind::Spend)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('amount_minor')
                    ->sum();
                $recovered = (int) RewardBudgetMovement::query()
                    ->where('program_id', $programId)
                    ->where('currency', $currency)
                    ->where('award_id', (string) $locked->award_id)
                    ->where('kind', RewardBudgetMovementKind::Recover)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('amount_minor')
                    ->sum();

                if ($amount <= 0 || $recovered + $amount > $spent) {
                    throw new InvalidArgumentException('Recovery exceeds the award\'s outstanding confirmed spend; refusing to mint budget.');
                }

                RewardBudgetLedger::append(
                    RewardBudgetMovementKind::Recover, "recover:{$locked->recovery_key}",
                    $programId, $currency, $amount,
                    (string) $locked->award_id, $actorType, $actorId, $actorRole, $decided,
                );

                RewardBudgetLedger::assertNonNegativeLegs(
                    RewardBudgetLedger::lockedBalances($programId, $currency, $funded)
                );
            }

            $evidence = $locked->evidence ?? [];
            $evidence['settlement'] = $recoveryEvidence;

            $locked->forceFill([
                'status' => RewardRecoveryStatus::Settled,
                'evidence' => $evidence,
                'settled_at' => $decided,
            ])->save();

            return $locked->refresh();
        });
    }
}
