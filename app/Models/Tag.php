<?php

namespace App\Models;

use App\Enums\TagType;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Tags\Tag as SpatieTag;

class Tag extends SpatieTag implements AuditableContract, Sortable
{
    /** @use HasFactory<TagFactory> */
    use AuditsModelChanges, HasFactory, HasUuids, SortableTrait;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array<string, string|bool>
     */
    public array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'order_column',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'slug' => 'array',
            'order_column' => 'integer',
        ];
    }

    /**
     * Get the type as an enum instance.
     */
    public function getTypeEnumAttribute(): ?TagType
    {
        return $this->type ? TagType::from($this->type) : null;
    }

    /**
     * Build the sort query scoped by type.
     *
     * @return Builder<static>
     */
    #[\Override]
    public function buildSortQuery(): Builder
    {
        $query = static::query();

        if ($this->type) {
            $query->where('type', $this->type);
        }

        return $query;
    }

    /**
     * Scope to filter by tag type.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, TagType|string $type): void
    {
        $value = $type instanceof TagType ? $type->value : $type;

        $query->where('type', $value);
    }
}
