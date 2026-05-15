<?php

namespace App\Models;

use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class AiModelPricing extends Model implements AuditableContract
{
    /** @use HasFactory<Factory<self>> */
    use AuditsModelChanges, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'model_pattern',
        'operation',
        'tier',
        'currency',
        'input_per_million',
        'output_per_million',
        'cache_write_input_per_million',
        'cache_read_input_per_million',
        'reasoning_per_million',
        'per_request',
        'per_image',
        'per_audio_second',
        'is_active',
        'priority',
        'starts_at',
        'ends_at',
        'notes',
        'meta',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'input_per_million' => 'decimal:8',
            'output_per_million' => 'decimal:8',
            'cache_write_input_per_million' => 'decimal:8',
            'cache_read_input_per_million' => 'decimal:8',
            'reasoning_per_million' => 'decimal:8',
            'per_request' => 'decimal:8',
            'per_image' => 'decimal:8',
            'per_audio_second' => 'decimal:8',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeAt(Builder $query, ?\DateTimeInterface $moment = null): void
    {
        $moment ??= now();

        $query
            ->where('is_active', true)
            ->where(fn (Builder $nested): Builder => $nested
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $moment)
            )
            ->where(fn (Builder $nested): Builder => $nested
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', $moment)
            );
    }
}
