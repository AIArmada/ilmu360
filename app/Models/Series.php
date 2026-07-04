<?php

namespace App\Models;

use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasFollowers;
use App\Models\Concerns\HasLanguages;
use Database\Factories\SeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string|null $series_type
 * @property string|null $status
 * @property string|null $visibility
 * @property bool|null $is_dynamic
 * @property array<string, mixed>|null $dynamic_rule_json
 * @property array<string, mixed>|null $metadata
 * @property bool $is_active
 */
class Series extends Model implements AuditableContract, HasMedia
{
    /** @use HasFactory<SeriesFactory> */
    use AuditsModelChanges, HasFactory, HasFollowers, HasLanguages, HasUuids, InteractsWithMedia;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'title',
        'slug',
        'description',
        'series_type',
        'status',
        'visibility',
        'is_dynamic',
        'dynamic_rule_json',
        'metadata',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'is_active',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_dynamic' => 'boolean',
            'dynamic_rule_json' => 'array',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return config('events.database.tables.event_series', 'event_series');
    }

    #[\Override]
    public function setAttribute($key, $value): mixed
    {
        if ($key === 'is_active') {
            return parent::setAttribute('status', $value ? 'active' : 'inactive');
        }

        return parent::setAttribute($key, $value);
    }

    #[\Override]
    public function getAttribute($key): mixed
    {
        if ($key === 'is_active') {
            return $this->isActiveFromAttributes();
        }

        return parent::getAttribute($key);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->isActiveFromAttributes();
    }

    /**
     * @return BelongsToMany<Event, $this, EventSeries, 'pivot'>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(
            Event::class,
            config('events.database.tables.event_series_items', 'event_series_items'),
            'event_series_id',
            'event_id',
        )
            ->using(EventSeries::class)
            ->withPivot('id', 'seriesable_type', 'seriesable_id', 'sort_order')
            ->wherePivot('seriesable_type', Event::class)
            ->withPivotValue('seriesable_type', Event::class)
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Register media collections for Spatie Media Library.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('gallery')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->withResponsiveImages();
    }

    /**
     * Register media conversions for optimized image delivery.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('cover', 'gallery')
            ->width(368)
            ->height(232)
            ->sharpen(10)
            ->format('webp');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }

    private function isActiveFromAttributes(): bool
    {
        return ($this->attributes['status'] ?? null) === 'active';
    }
}
