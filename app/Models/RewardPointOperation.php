<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RewardPointOperationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Durable logical-operation identity for multi-lot point movements.
 *
 * Reserve, direct-debit/capture, and refund allocations write per-lot
 * movement rows whose unique keys differ per lot, so disjoint recipients lock
 * disjoint lots and can otherwise commit the same logical operation twice.
 * Every such operation first claims one immutable authority row keyed by
 * (kind, operation_key) — the caller-owned reservation, debit, or refund key
 * — binding recipient/family/unit/amount plus the kind-specific terms
 * (reservation or linked debit aggregate). Same-key replays collapse onto the
 * winner only when every bound term matches; mismatched reuse fails
 * explicitly and creates no new effect. The row is written in the same
 * transaction as its movements, so it exists if and only if the operation
 * committed; failed allocations roll it back with everything else.
 */
class RewardPointOperation extends Model
{
    /** @use HasFactory<RewardPointOperationFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'kind',
        'operation_key',
        'recipient_type',
        'recipient_id',
        'family_key',
        'unit',
        'points',
        'reservation_key',
        'linked_aggregate_key',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward point operations are immutable operation identity.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward point operations cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardPointOperationFactory
    {
        return RewardPointOperationFactory::new();
    }
}
