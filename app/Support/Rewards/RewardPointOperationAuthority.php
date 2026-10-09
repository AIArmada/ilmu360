<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Models\RewardPointOperation;
use InvalidArgumentException;

/**
 * Claim helper for the logical-operation authority.
 *
 * Callers claim BEFORE locking any lots: the unique (kind, operation_key)
 * insert is the single serialization point for concurrent same-key
 * operations, including contenders whose lots are disjoint. In-transaction
 * claims observe already-committed winners; concurrent uncommitted claims
 * collide on the unique insert and the loser collapses onto the winner (or
 * throws on mismatched terms) outside its rolled-back transaction.
 *
 * @phpstan-type OperationTerms array{
 *     recipient_type: string,
 *     recipient_id: string,
 *     family_key: string,
 *     unit: string,
 *     points: int,
 *     reservation_key?: ?string,
 *     linked_aggregate_key?: ?string,
 * }
 */
final class RewardPointOperationAuthority
{
    public const string KIND_RESERVE = 'reserve';

    public const string KIND_DEBIT = 'debit';

    public const string KIND_REFUND = 'refund';

    /**
     * @param  OperationTerms  $terms
     */
    public static function claim(string $kind, string $operationKey, array $terms, string $label): RewardPointOperation
    {
        $existing = self::find($kind, $operationKey);

        if ($existing instanceof RewardPointOperation) {
            self::assertTermsMatch($existing, $terms, $label);

            return $existing;
        }

        return RewardPointOperation::query()->create([
            'kind' => $kind,
            'operation_key' => $operationKey,
            'recipient_type' => $terms['recipient_type'],
            'recipient_id' => $terms['recipient_id'],
            'family_key' => $terms['family_key'],
            'unit' => $terms['unit'],
            'points' => $terms['points'],
            'reservation_key' => $terms['reservation_key'] ?? null,
            'linked_aggregate_key' => $terms['linked_aggregate_key'] ?? null,
        ]);
    }

    /**
     * Outside-transaction winner resolution for unique-conflict recovery.
     * Returns null when no winner committed (the caller rethrows); a committed
     * winner with mismatched terms throws explicitly instead of exposing
     * foreign rows.
     *
     * @param  OperationTerms  $terms
     */
    public static function resolveWinner(
        string $kind,
        string $operationKey,
        array $terms,
        string $label,
    ): ?RewardPointOperation {
        $winner = self::find($kind, $operationKey);

        if (! $winner instanceof RewardPointOperation) {
            return null;
        }

        self::assertTermsMatch($winner, $terms, $label);

        return $winner;
    }

    public static function find(string $kind, string $operationKey): ?RewardPointOperation
    {
        return RewardPointOperation::query()
            ->where('kind', $kind)
            ->where('operation_key', $operationKey)
            ->first();
    }

    /**
     * @param  OperationTerms  $terms
     */
    public static function assertTermsMatch(RewardPointOperation $operation, array $terms, string $label): void
    {
        if ((string) $operation->recipient_type !== (string) $terms['recipient_type']
            || (string) $operation->recipient_id !== (string) $terms['recipient_id']
            || (string) $operation->family_key !== (string) $terms['family_key']
            || (string) $operation->unit !== (string) $terms['unit']
        ) {
            throw new InvalidArgumentException("{$label} is already bound to a different recipient, family, or unit; supply a fresh key.");
        }

        $reservation = $terms['reservation_key'] ?? null;
        $linked = $terms['linked_aggregate_key'] ?? null;

        if (($operation->reservation_key === null ? null : (string) $operation->reservation_key) !== ($reservation === null ? null : (string) $reservation)
            || ($operation->linked_aggregate_key === null ? null : (string) $operation->linked_aggregate_key) !== ($linked === null ? null : (string) $linked)
        ) {
            if ($operation->kind === self::KIND_REFUND) {
                throw new InvalidArgumentException('Refund operation key is already bound to a different debit aggregate; supply a fresh key.');
            }

            throw new InvalidArgumentException("{$label} is already bound to different terms; supply a fresh key.");
        }

        if ((int) $operation->points !== (int) $terms['points']) {
            throw new InvalidArgumentException("{$label} is already bound to a different amount; supply a fresh key.");
        }
    }
}
