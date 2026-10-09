<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Actions\Rewards\CreateRewardProgramAction;
use App\Enums\Rewards\RewardBudgetMovementKind;
use App\Models\RewardBudgetMovement;
use App\Models\RewardFundingEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Per-program, per-currency budget ledger.
 *
 * Balances derive from immutable rows only: confirmed funding entries plus
 * the append-only movement stream. No currency is ever summed with or
 * converted into another; no funding entry is shared across programs.
 *
 * Invariant (per program + currency):
 *   funded == available + reserved + net_spent
 * where reserved = reserve - release, net_spent = spend - recover, and
 * recover rows exist only for confirmed recoveries. Uncertain provider
 * operations stay reserved; nothing releases money while an old operation
 * may still succeed.
 *
 * Lock order: callers hold the award lock first, then funding-entry rows in
 * sorted id order, then append exactly one movement. Movement keys are
 * stable semantic keys (`{kind}:{award_id}:{n}`), so a racing duplicate
 * replays the committed row instead of double-moving.
 *
 * Cross-domain order (payout lifecycle): package payout/operation rows,
 * then the recipient affiliate, then award, then the payout request,
 * then funding, then append. The accepted backing policy locks in the
 * same relative order (affiliate before award before funding inside its
 * own transaction), so concurrent submits, cancels, reconciles, and
 * confirmations serialize instead of deadlocking across engines.
 */
final class RewardBudgetLedger
{
    /**
     * @return array{funded: int, reserved: int, net_spent: int, available: int}
     */
    public static function balances(string $programId, string $currency): array
    {
        $currency = self::normalizeCurrency($currency);

        $funded = (int) RewardFundingEntry::query()
            ->where('program_id', $programId)
            ->where('currency', $currency)
            ->sum('amount_minor');

        $byKind = RewardBudgetMovement::query()
            ->where('program_id', $programId)
            ->where('currency', $currency)
            ->selectRaw('kind, COALESCE(SUM(amount_minor), 0) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $reserve = (int) ($byKind[RewardBudgetMovementKind::Reserve->value] ?? 0);
        $release = (int) ($byKind[RewardBudgetMovementKind::Release->value] ?? 0);
        $spend = (int) ($byKind[RewardBudgetMovementKind::Spend->value] ?? 0);
        $recover = (int) ($byKind[RewardBudgetMovementKind::Recover->value] ?? 0);

        $reserved = $reserve - $release;
        $netSpent = $spend - $recover;

        return [
            'funded' => $funded,
            'reserved' => $reserved,
            'net_spent' => $netSpent,
            'available' => $funded - $reserved - $netSpent,
        ];
    }

    public static function assertInvariant(string $programId, string $currency): void
    {
        $balances = self::balances($programId, $currency);

        if ($balances['reserved'] < 0 || $balances['net_spent'] < 0 || $balances['available'] < 0
            || $balances['funded'] !== $balances['available'] + $balances['reserved'] + $balances['net_spent']
        ) {
            throw new InvalidArgumentException('Reward budget invariant violated; refusing further movement.');
        }
    }

    /**
     * Terminal release key bound to the reservation attempt it releases.
     * Each requeue reserves under a fresh `reserve:{award}:attempt:{n}`
     * key, so each terminal release must carry the same attempt — a
     * shared key would replay the first attempt's release and leak every
     * later attempt's reservation.
     */
    public static function releaseKeyForAttempt(string $awardId, int $attempt, string $reason): string
    {
        return "release:{$awardId}:attempt:".max(1, $attempt).":{$reason}";
    }

    /**
     * Current reservation attempt from request evidence (1 when the
     * request never requeued).
     *
     * @param  array<string, mixed>|null  $evidence
     */
    public static function attemptOf(?array $evidence): int
    {
        return max(1, (int) ($evidence['attempt'] ?? 1));
    }

    /**
     * Held-obligation reserve key for one award + obligation epoch. The
     * epoch rotates on overturn-after-release so a re-obligation never
     * replays (and silently skips) under a retired key.
     */
    public static function obligateKey(string $awardId, int $epoch): string
    {
        return "obligate:{$awardId}:e".max(0, $epoch);
    }

    /**
     * Release key retiring one held-obligation epoch.
     */
    public static function releaseObligateKey(string $awardId, int $epoch): string
    {
        return 'release:'.self::obligateKey($awardId, $epoch);
    }

    public static function normalizeCurrency(string $currency): string
    {
        $currency = mb_strtoupper(mb_trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Budget currency must be an explicit 3-letter ISO-4217 code.');
        }

        return $currency;
    }

    /**
     * Lock the program's funding rows for this currency in sorted id order.
     * Must be called inside the caller's transaction while holding the award
     * lock; returns the confirmed funded total observed under lock.
     *
     * The scan itself is the locking read: locking reads observe the latest
     * committed rows on every isolation, so no ordinary pre-read may feed
     * the id list (a pre-read under an established repeatable-read
     * snapshot would silently miss young funding rows).
     */
    public static function lockFunding(string $programId, string $currency): int
    {
        $currency = self::normalizeCurrency($currency);

        return (int) RewardFundingEntry::query()
            ->where('program_id', $programId)
            ->where('currency', $currency)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('amount_minor')
            ->sum();
    }

    /**
     * Sum movements from rows locked in sorted id order. Locking reads
     * observe the latest committed rows on every isolation; ordinary
     * aggregates under an established snapshot do not, so the sums are
     * computed in PHP from the locked rows (FOR UPDATE cannot pair with
     * aggregate SQL on PostgreSQL). Every writer holds these same locks
     * before appending.
     *
     * @return array{funded: int, reserved: int, net_spent: int, available: int}
     */
    public static function lockedBalances(string $programId, string $currency, int $lockedFunded): array
    {
        $currency = self::normalizeCurrency($currency);

        $rows = RewardBudgetMovement::query()
            ->where('program_id', $programId)
            ->where('currency', $currency)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['kind', 'amount_minor']);

        $byKind = [];

        foreach ($rows as $row) {
            $key = $row->kind instanceof RewardBudgetMovementKind ? $row->kind->value : (string) $row->kind;
            $byKind[$key] = ($byKind[$key] ?? 0) + (int) $row->amount_minor;
        }

        $reserved = (int) ($byKind[RewardBudgetMovementKind::Reserve->value] ?? 0)
            - (int) ($byKind[RewardBudgetMovementKind::Release->value] ?? 0);
        $netSpent = (int) ($byKind[RewardBudgetMovementKind::Spend->value] ?? 0)
            - (int) ($byKind[RewardBudgetMovementKind::Recover->value] ?? 0);

        return [
            'funded' => $lockedFunded,
            'reserved' => $reserved,
            'net_spent' => $netSpent,
            'available' => $lockedFunded - $reserved - $netSpent,
        ];
    }

    /**
     * Terminal sanity: funded, reserved, and net spent can never go
     * negative. Available MAY go negative for an underfunded program
     * (earned obligations exceed confirmed funding — truthful, and the
     * approval gate refuses until funding lands), but the legs never do:
     * a negative leg means a release without its reserve.
     *
     * @param  array{funded: int, reserved: int, net_spent: int, available: int}  $balances
     */
    public static function assertNonNegativeLegs(array $balances): void
    {
        foreach (['funded', 'reserved', 'net_spent'] as $leg) {
            if (($balances[$leg] ?? 0) < 0) {
                throw new InvalidArgumentException("Budget invariant violated: [{$leg}] went negative.");
            }
        }
    }

    public static function append(
        RewardBudgetMovementKind $kind,
        string $movementKey,
        string $programId,
        string $currency,
        int $amountMinor,
        ?string $awardId,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): RewardBudgetMovement {
        if ($movementKey === '' || mb_strlen($movementKey) > 191) {
            throw new InvalidArgumentException('Budget movement key must be 1..191 characters.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Budget movement amount must be a positive minor-unit amount.');
        }

        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        try {
            return DB::transaction(static fn (): RewardBudgetMovement => RewardBudgetMovement::query()->create([
                'movement_key' => $movementKey,
                'program_id' => $programId,
                'currency' => self::normalizeCurrency($currency),
                'kind' => $kind,
                'award_id' => $awardId,
                'amount_minor' => $amountMinor,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_role' => $actorRole,
                'decided_at' => $decided,
            ]), 1);
        } catch (QueryException $exception) {
            if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                throw $exception;
            }

            // Locking re-read: current on every isolation, so a racing
            // duplicate is replayed instead of misreported.
            $existing = RewardBudgetMovement::query()
                ->where('movement_key', $movementKey)
                ->lockForUpdate()
                ->first();

            // Replay requires the FULL money terms — kind, program,
            // currency, amount, and award — never kind/amount alone.
            // Provenance (actor/decided) stays with the first writer.
            if ($existing instanceof RewardBudgetMovement
                && $existing->kind === $kind
                && (string) $existing->program_id === $programId
                && mb_strtoupper((string) $existing->currency) === self::normalizeCurrency($currency)
                && (int) $existing->amount_minor === $amountMinor
                && (string) ($existing->award_id ?? '') === (string) ($awardId ?? '')
            ) {
                return $existing;
            }

            throw new InvalidArgumentException('Budget movement key is already bound to different terms; supply a fresh key.', 0, $exception);
        }
    }
}
