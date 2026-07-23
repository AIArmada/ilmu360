<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventTaxonomyCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Carbon;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Tags\Tag as SpatieTag;

/**
 * @property string $id
 * @property EventTaxonomyCode|null $type_enum
 * @property string $type
 * @property array<string, string> $name
 * @property array<string, string>|null $slug
 * @property int|null $order_column
 * @property string $status
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_state_change_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tag extends SpatieTag implements Sortable
{
    use HasUuids;
    use SortableTrait;

    /** @var array<string, mixed> */
    public array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'verified_at',
        'last_state_change_at',
        'order_column',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'verified_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
        ];
    }

    public function getTypeEnumAttribute(): ?EventTaxonomyCode
    {
        return $this->type !== null
            ? EventTaxonomyCode::tryFrom($this->type)
            : null;
    }
}
