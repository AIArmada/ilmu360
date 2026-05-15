<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $redirectable_type
 * @property string $redirectable_id
 * @property string $source_slug
 * @property string $source_path
 * @property string $destination_slug
 * @property string $destination_path
 * @property CarbonImmutable|null $first_visited_at
 * @property CarbonImmutable|null $last_redirected_at
 * @property int $redirect_count
 */
class SlugRedirect extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'redirectable_type',
        'redirectable_id',
        'source_slug',
        'source_path',
        'destination_slug',
        'destination_path',
        'first_visited_at',
        'last_redirected_at',
        'redirect_count',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'first_visited_at' => 'immutable_datetime',
            'last_redirected_at' => 'immutable_datetime',
            'redirect_count' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function redirectable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forRedirectable(Builder $query, Model $model): void
    {
        $query
            ->where('redirectable_type', $model->getMorphClass())
            ->where('redirectable_id', (string) $model->getKey());
    }
}
