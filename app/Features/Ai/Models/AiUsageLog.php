<?php

namespace App\Features\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AiUsageLog extends Model
{
    #[\Override]
    protected $table = 'ai_usage_logs';

    /** @use HasFactory<Factory<self>> */
    use HasFactory, HasUuids;

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'invocation_id',
        'operation',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'cache_write_input_tokens',
        'cache_read_input_tokens',
        'reasoning_tokens',
        'total_tokens',
        'cost_usd',
        'currency',
        'user_id',
        'meta',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_write_input_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_usd' => 'decimal:8',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            throw new RuntimeException('The users auth provider must reference an Eloquent model.');
        }

        return $this->belongsTo($model, 'user_id');
    }
}
