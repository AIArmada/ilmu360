<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One confirmed funding source (bank receipt, grant letter, treasury
 * reference), identified GLOBALLY by source_ref + currency — never per
 * program. Programs draw bounded allocations from sources; the source
 * total is the overallocation ceiling across every program. Immutable
 * after creation.
 *
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable|null $funded_at
 */
class RewardFundingSource extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_ref',
        'currency',
        'amount_minor',
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
            throw new LogicException('Reward funding sources are immutable; record a correcting source instead.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward funding sources cannot be deleted.');
        });
    }
}
