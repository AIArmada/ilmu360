<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RewardEnrollmentAcceptanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable acceptance row. Reacceptance always appends a new row (next seq),
 * even for identical terms — no unique conflict. Effective terms at earning
 * time T are the latest acceptance with accepted_at <= T.
 */
class RewardEnrollmentAcceptance extends Model
{
    /** @use HasFactory<RewardEnrollmentAcceptanceFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'enrollment_id',
        'seq',
        'policy_version_id',
        'material_terms_hash',
        'terms_label',
        'accepted_snapshot',
        'accepted_at',
        'accepted_via',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'accepted_snapshot' => 'array',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RewardEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(RewardEnrollment::class, 'enrollment_id');
    }

    /**
     * @return BelongsTo<RewardProgramVersion, $this>
     */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(RewardProgramVersion::class, 'policy_version_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward enrollment acceptances are immutable.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward enrollment acceptances cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardEnrollmentAcceptanceFactory
    {
        return RewardEnrollmentAcceptanceFactory::new();
    }
}
