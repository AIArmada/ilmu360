<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One bounded allocation of a confirmed funding source to one program +
 * currency. The source row carries the global total; entries draw from
 * it without ever overallocating. Immutable after creation: funding
 * evidence is operator-supplied setup input, never edited in place. A
 * correction is a new entry, never a rewrite.
 *
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable|null $funded_at
 */
class RewardFundingEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'program_id',
        'currency',
        'amount_minor',
        'source_ref',
        'source_id',
        'evidence',
        'funded_by_type',
        'funded_by_id',
        'funded_by_role',
        'funded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'evidence' => 'array',
            'funded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward funding entries are immutable; record a correcting entry instead.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward funding entries cannot be deleted.');
        });
    }
}
