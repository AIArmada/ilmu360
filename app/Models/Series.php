<?php

namespace App\Models;

use AIArmada\Engagement\Models\Follow;
use AIArmada\Events\Models\EventSeries as PackageEventSeries;
use AIArmada\Events\Models\EventSeriesItemPivot;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasLanguages;
use Database\Factories\SeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Image\Enums\Fit;
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
 */
class Series extends PackageEventSeries implements AuditableContract, HasMedia
{
    protected static string $ownerScopeConfigKey = 'series.owner';

    /** @use HasFactory<SeriesFactory> */
    use AuditsModelChanges, HasFactory, HasLanguages, InteractsWithMedia;

    #[\Override]
    protected static function newFactory(): SeriesFactory
    {
        return SeriesFactory::new();
    }

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

    /**
     * @return BelongsToMany<Event, $this, EventSeriesItemPivot, 'pivot'>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(
            Event::class,
            config('events.database.tables.event_series_items', 'event_series_items'),
            'event_series_id',
            'event_id',
        )
            ->using(EventSeriesItemPivot::class)
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
            ->fit(Fit::Crop, 1920, 1080)
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

    /**
     * @return MorphMany<Follow, $this>
     */
    public function follows(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    /**
     * @return MorphToMany<User, $this>
     */
    public function followers(): MorphToMany
    {
        $table = (new Follow)->getTable();

        return $this->morphToMany(User::class, 'followable', $table, 'followable_id', 'follower_id')
            ->where("{$table}.status", 'active');
    }

    public function followersCount(): int
    {
        return $this->follows()->active()->count();
    }

    public function isFollowedBy(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $this->follows()->active()->where('follower_id', $user->getKey())->exists();
    }
}
