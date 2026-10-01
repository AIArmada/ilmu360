<?php

namespace App\Models;

use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Contacting\Concerns\HasSocialProfiles;
use AIArmada\Events\Models\Venue as PackageVenue;
use AIArmada\Events\Models\VenueFacility;
use App\Enums\InstitutionVenueRole;
use App\Enums\VenueType;
use App\Models\Concerns\AuditsModelChanges;
use Carbon\CarbonImmutable;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property VenueType|string|null $venue_type
 * @property-read Collection<int, VenueFacility> $facilities
 * @property string|null $status
 * @property string|null $verified_by
 * @property string|null $visibility
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $last_state_change_at
 * @property array<string, mixed>|null $metadata
 *
 * @extends PackageVenue<Space>
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
        'venue_type',
        'status',
        'verified_at',
        'verified_by',
        'rejected_at',
        'last_state_change_at',
        'visibility',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'venue_type' => VenueType::class,
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
        ];
    }

    #[\Override]
    protected static function newFactory(): VenueFactory
    {
        return VenueFactory::new();
    }

    #[\Override]
    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (self $venue): void {
            if ($venue->isDirty('status')) {
                $now = now();
                $venue->last_state_change_at = $now;

                match ((string) $venue->status) {
                    'verified' => $venue->verified_at ??= $now,
                    'rejected' => $venue->rejected_at ??= $now,
                    default => null,
                };

                if ((string) $venue->status === 'verified') {
                    $venue->verified_by ??= auth()->id();
                }
            }
        });
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'default_venue_id');
    }

    /**
     * @return HasMany<Space, $this>
     */
    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class, 'venue_id');
    }

    /**
     * @return BelongsToMany<Institution, $this, InstitutionVenue, 'pivot'>
     */
    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'institution_venue')
            ->using(InstitutionVenue::class)
            ->withPivot(['id', 'role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Institution, $this, InstitutionVenue, 'pivot'>
     */
    public function operatedByInstitutions(): BelongsToMany
    {
        return $this->institutions()->wherePivot('role', InstitutionVenueRole::Operated->value);
    }

    /**
     * @return BelongsToMany<Institution, $this, InstitutionVenue, 'pivot'>
     */
    public function preferredByInstitutions(): BelongsToMany
    {
        return $this->institutions()->wherePivot('role', InstitutionVenueRole::Preferred->value);
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
     * Delete the row atomically with its bridge links: ordinary
     * Model::delete() opens no transaction of its own.
     */
    #[\Override]
    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function (): ?bool {
            $deleted = parent::delete();

            if ($deleted) {
                $this->deleteInstitutionVenueLinks();
            }

            return $deleted;
        });
    }

    /**
     * Remove this venue's institution bridge rows only; linked institutions,
     * spaces, and venues are never touched.
     */
    private function deleteInstitutionVenueLinks(): void
    {
        InstitutionVenue::query()->where('venue_id', $this->getKey())->delete();
    }

    public function getPublicMainUrlAttribute(): string
    {
        if ($this->hasMedia('main')) {
            $mainMedia = $this->getFirstMedia('main');

            if ($mainMedia instanceof Media) {
                return $mainMedia->getAvailableUrl(['thumb']) ?: $mainMedia->getUrl();
            }
        }

        return asset('images/placeholders/venue.png');
    }

    /**
     * Register media collections for Spatie Media Library.
     */
    #[\Override]
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('main')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/venue.png'))
            ->withResponsiveImages()
            ->singleFile();

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
            ->performOnCollections('main')
            ->fit(Fit::Max, 1920, 1080)
            ->sharpen(10)
            ->format('webp');

        $this->addMediaConversion('banner')
            ->performOnCollections('cover')
            ->fit(Fit::Crop, 1920, 1080)
            ->format('webp');

        $this->addMediaConversion('gallery_thumb')
            ->performOnCollections('gallery')
            ->fit(Fit::Max, 1080, 1080)
            ->sharpen(10)
            ->format('webp');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
