<?php

namespace App\Models;

use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Contacting\Concerns\HasSocialProfiles;
use AIArmada\Events\Models\Venue as PackageVenue;
use App\Enums\VenueType;
use App\Models\Builders\VenueBuilder;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property VenueType|string|null $type
 * @property VenueType|string|null $venue_type
 * @property array<int, mixed>|string|null $facilities
 * @property string|null $status
 * @property string|null $visibility
 * @property float|int|string|null $latitude
 * @property float|int|string|null $longitude
 * @property string|null $google_maps_url
 * @property string|null $map_url
 * @property array<string, mixed>|null $metadata
 */
class Venue extends PackageVenue implements AuditableContract
{
    /** @use HasFactory<VenueFactory> */
    use AuditsModelChanges, HasAddresses, HasContactMethods, HasFactory, HasSocialProfiles, KeepsDeletedModels;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'parent_venue_id',
        'name',
        'slug',
        'description',
        'type',
        'venue_type',
        'line1',
        'line2',
        'city',
        'state',
        'postcode',
        'country_code',
        'country',
        'latitude',
        'longitude',
        'google_place_id',
        'google_maps_url',
        'waze_url',
        'map_url',
        'directions',
        'geocoded_at',
        'geocoding_source',
        'status',
        'visibility',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'venue_type' => VenueType::class,
        ];
    }

    #[\Override]
    public function newEloquentBuilder($query): VenueBuilder
    {
        return new VenueBuilder($query);
    }

    #[\Override]
    protected static function newFactory(): VenueFactory
    {
        return VenueFactory::new();
    }

    #[\Override]
    public function setAttribute($key, $value): mixed
    {
        // Product field name → package column (single store).
        if ($key === 'type') {
            $normalized = $value instanceof VenueType ? $value->value : $value;

            return parent::setAttribute('venue_type', $normalized);
        }

        return parent::setAttribute($key, $value);
    }

    #[\Override]
    public function getAttribute($key): mixed
    {
        // Product field name → package column (single store).
        if ($key === 'type') {
            $value = parent::getAttribute('venue_type');

            if ($value instanceof VenueType) {
                return $value;
            }

            if (is_string($value) && $value !== '') {
                return VenueType::tryFrom($value) ?? $value;
            }

            return $value;
        }

        return parent::getAttribute($key);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'default_venue_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', ['verified', 'pending']);
    }

    /**
     * Register media collections for Spatie Media Library.
     */
    #[\Override]
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/venue.png'))
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

        $this->addMediaConversion('banner')
            ->performOnCollections('cover')
            ->fit(Fit::Crop, 1200, 675)
            ->format('webp');
    }
}
