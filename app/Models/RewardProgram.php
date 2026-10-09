<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use Database\Factories\RewardProgramFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Mutable program-family lifecycle row. Holds only identity, ownership scope,
 * status, transition timestamps, and the current-version pointer. Rule/terms
 * snapshots live on immutable versions; this row never carries them.
 *
 * Timestamp mapping: draft inception is created_at; ready/active/paused/closed
 * each record their FIRST transition time immutably (a resumed program keeps
 * its first paused_at as evidence; later cycles are not rewritten).
 *
 * @property RewardProgramMode $mode
 * @property RewardProgramStatus $status
 */
class RewardProgram extends Model
{
    /** @use HasFactory<RewardProgramFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'family_key',
        'title',
        'mode',
        'owner_type',
        'owner_id',
        'purpose',
        'status',
        'current_version_id',
        'ready_at',
        'active_at',
        'paused_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => RewardProgramMode::class,
            'status' => RewardProgramStatus::class,
            'ready_at' => 'immutable_datetime',
            'active_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<RewardProgramVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(RewardProgramVersion::class, 'program_id')->orderBy('version');
    }

    /**
     * @return HasMany<RewardProgramPublication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(RewardProgramPublication::class, 'program_id')->orderBy('version');
    }

    /**
     * @return HasMany<RewardEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(RewardEnrollment::class, 'program_id');
    }

    /**
     * @return BelongsTo<RewardProgramVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(RewardProgramVersion::class, 'current_version_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardProgram $program): void {
            foreach (['family_key', 'mode'] as $field) {
                if ($program->isDirty($field)) {
                    throw new LogicException("Reward program [{$field}] is immutable after creation.");
                }
            }

            foreach (['ready_at', 'active_at', 'paused_at', 'closed_at'] as $field) {
                $original = $program->getOriginal($field);

                if ($original !== null && $program->isDirty($field)) {
                    throw new LogicException("Reward program [{$field}] is an immutable transition timestamp.");
                }
            }

            if ($program->getOriginal('current_version_id') !== null && $program->current_version_id === null) {
                throw new LogicException('Reward program current_version_id pointer cannot be cleared once set.');
            }

            if ($program->isDirty('status')) {
                $original = $program->getOriginal('status');
                $from = $original instanceof RewardProgramStatus
                    ? $original
                    : RewardProgramStatus::tryFrom((string) $original);
                $to = $program->status;

                if (! $from instanceof RewardProgramStatus
                    || ! $from->canTransitionTo($to)
                ) {
                    throw new LogicException('Invalid reward program status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward programs cannot be deleted; close them instead.');
        });
    }

    protected static function newFactory(): RewardProgramFactory
    {
        return RewardProgramFactory::new();
    }
}
