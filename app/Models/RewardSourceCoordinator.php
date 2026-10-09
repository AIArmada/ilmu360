<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use Database\Factories\RewardSourceCoordinatorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Global per-source evaluation serializer.
 *
 * Unique (source, component, role): every evaluation locks its coordinator
 * row first, so cross-program claim checks and inserts for one source can
 * never interleave. Concurrent coordinator creation surfaces as a unique
 * violation that the caller retries outside its transaction. Rows are
 * coordination evidence — never updated or deleted.
 *
 * @property RewardComponent $component
 * @property RewardBeneficiaryRole $beneficiary_role
 */
class RewardSourceCoordinator extends Model
{
    /** @use HasFactory<RewardSourceCoordinatorFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'source_business_key',
        'component',
        'beneficiary_role',
    ];

    protected function casts(): array
    {
        return [
            'component' => RewardComponent::class,
            'beneficiary_role' => RewardBeneficiaryRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward source coordinators are immutable.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward source coordinators cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardSourceCoordinatorFactory
    {
        return RewardSourceCoordinatorFactory::new();
    }
}
