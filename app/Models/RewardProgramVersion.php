<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RewardProgramVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Immutable published rules/terms snapshot. Rows are never updated or
 * deleted after publication; supersession appends a new version plus a
 * publication row. Effective windows derive from the next version's
 * effective_from — there is no mutable effective_to.
 *
 * @property array<string, mixed> $rules
 * @property array<string, mixed> $terms
 * @property list<string> $compatible_with
 */
class RewardProgramVersion extends Model
{
    /** @use HasFactory<RewardProgramVersionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'program_id',
        'version',
        'effective_from',
        'material_terms_hash',
        'rules',
        'terms',
        'compatible_with',
        'published_by_type',
        'published_by_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'immutable_datetime',
            'rules' => 'array',
            'terms' => 'array',
            'compatible_with' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RewardProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(RewardProgram::class, 'program_id');
    }

    /**
     * @return HasOne<RewardProgramPublication, $this>
     */
    public function publication(): HasOne
    {
        return $this->hasOne(RewardProgramPublication::class, 'version_id');
    }

    /**
     * @return list<string>
     */
    public function componentTypes(): array
    {
        $components = $this->rules['components'] ?? [];

        if (! is_array($components)) {
            return [];
        }

        $types = [];

        foreach ($components as $component) {
            if (is_array($component) && is_string($component['type'] ?? null)) {
                $types[] = $component['type'];
            }
        }

        return $types;
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Reward program versions are immutable after publication.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward program versions cannot be deleted.');
        });
    }

    protected static function newFactory(): RewardProgramVersionFactory
    {
        return RewardProgramVersionFactory::new();
    }
}
