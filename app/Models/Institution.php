<?php

namespace App\Models;

use App\Enums\InstitutionType;
use App\Enums\MemberSubjectType;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasAddress;
use App\Models\Concerns\HasContacts;
use App\Models\Concerns\HasDonationChannels;
use App\Models\Concerns\HasFollowers;
use App\Models\Concerns\HasLanguages;
use App\Models\Concerns\HasSocialMedia;
use Carbon\CarbonInterface;
use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Institution extends Model implements AuditableContract, HasMedia
{
    public const string PUBLIC_DIRECTORY_SESSION_KEY = 'public_institutions_directory_seed';

    /** @use HasFactory<InstitutionFactory> */
    use AuditsModelChanges, HasAddress, HasContacts, HasDonationChannels, HasFactory, HasFollowers, HasLanguages, HasSocialMedia, HasUuids, InteractsWithMedia, KeepsDeletedModels, Searchable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'nickname',
        'slug',
        'description',

        'status',
        'is_active',
        'allow_public_event_submission',
        'public_submission_locked_at',
        'public_submission_locked_by',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => InstitutionType::class,
            'is_active' => 'boolean',
            'allow_public_event_submission' => 'boolean',
            'public_submission_locked_at' => 'datetime',
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_active
            && in_array((string) $this->status, ['verified', 'pending'], true);
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged([
            'type',
            'name',
            'nickname',
            'description',
            'slug',
            'status',
            'is_active',
        ]);
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query
            ->with('address')
            ->where('institutions.is_active', true)
            ->whereIn('institutions.status', ['verified', 'pending']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        if ($this->usesScoutDatabaseDriver()) {
            return $this->toScoutDatabaseSearchableArray();
        }

        $this->loadMissing('address');

        $address = $this->addressModel;
        $type = $this->type;
        $updatedAt = $this->updated_at ?? now();

        return [
            'id' => (string) $this->getKey(),
            'type' => $type instanceof InstitutionType ? $type->value : (is_string($type) ? $type : null),
            'name' => (string) $this->name,
            'display_name' => $this->display_name,
            'nickname' => filled($this->nickname) ? (string) $this->nickname : null,
            'description' => $this->searchableDescriptionText(),
            'search_text' => $this->searchableText(),
            'slug' => (string) $this->slug,
            'status' => (string) $this->status,
            'is_active' => (bool) $this->is_active,
            'country_id' => $address?->country_id,
            'state_id' => $address?->state_id,
            'district_id' => $address?->district_id,
            'subdistrict_id' => $address?->subdistrict_id,
            'updated_at' => $updatedAt->timestamp,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function toScoutDatabaseSearchableArray(): array
    {
        return array_filter([
            'name' => (string) $this->name,
            'nickname' => filled($this->nickname) ? (string) $this->nickname : null,
            'description' => filled($this->description) ? (string) $this->description : null,
            'slug' => (string) $this->slug,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function usesScoutDatabaseDriver(): bool
    {
        return (string) config('scout.driver') === 'database';
    }

    public function getDisplayNameAttribute(): string
    {
        return self::formatDisplayName($this->name, $this->nickname);
    }

    public static function formatDisplayName(?string $name, ?string $nickname): string
    {
        $normalizedName = trim((string) $name);
        $normalizedNickname = is_string($nickname) ? trim($nickname) : '';

        if ($normalizedNickname === '') {
            return $normalizedName;
        }

        return $normalizedName === ''
            ? $normalizedNickname
            : "{$normalizedName} ({$normalizedNickname})";
    }

    private function searchableText(): string
    {
        return trim(implode(' ', array_filter([
            trim($this->display_name),
            trim((string) $this->name),
            trim((string) $this->nickname),
            $this->searchableDescriptionText(),
        ])));
    }

    private function searchableDescriptionText(): ?string
    {
        $description = trim(strip_tags((string) $this->description));

        return $description !== '' ? $description : null;
    }

    public function getPublicLogoUrlAttribute(): string
    {
        return $this->preferredMediaUrl($this->getFirstMedia('logo'), ['thumb']) ?? '';
    }

    public function getPublicCoverUrlAttribute(): string
    {
        return $this->preferredMediaUrl($this->getFirstMedia('cover'), ['banner']) ?? '';
    }

    public function getPublicImageUrlAttribute(): string
    {
        if ($this->public_cover_url !== '') {
            return $this->public_cover_url;
        }

        if ($this->public_logo_url !== '') {
            return $this->public_logo_url;
        }

        return asset('images/placeholders/institution.png');
    }

    /**
     * @param  list<string>  $preferredConversions
     */
    private function preferredMediaUrl(?Media $media, array $preferredConversions = []): ?string
    {
        if (! $media instanceof Media) {
            return null;
        }

        $availableUrl = $preferredConversions === []
            ? $media->getUrl()
            : $media->getAvailableUrl($preferredConversions);

        if ($availableUrl !== '') {
            return $availableUrl;
        }

        $originalUrl = $media->getUrl();

        return $originalUrl !== '' ? $originalUrl : null;
    }

    /**
     * @return BelongsToMany<Space, $this>
     */
    public function spaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'institution_space')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return BelongsToMany<Speaker, $this>
     */
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'institution_speaker')
            ->withPivot(['position', 'is_primary', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'institution_user')
            ->withTimestamps();
    }

    /**
     * @return HasMany<MemberInvitation, $this>
     */
    public function memberInvitations(): HasMany
    {
        return $this->hasMany(MemberInvitation::class, 'subject_id')
            ->where('subject_type', MemberSubjectType::Institution->value);
    }

    /**
     * @return HasMany<MembershipClaim, $this>
     */
    public function membershipClaims(): HasMany
    {
        return $this->hasMany(MembershipClaim::class, 'subject_id')
            ->where('subject_type', MemberSubjectType::Institution->value);
    }

    /**
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'entity');
    }

    /**
     * Register media collections for Spatie Media Library.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])
            ->useFallbackUrl(asset('images/placeholders/institution.png'))
            ->singleFile();

        $this->addMediaCollection('cover')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/institution.png'))
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
            ->performOnCollections('logo')
            ->width(100)
            ->height(100)
            ->sharpen(10)
            ->format('webp');

        $this->addMediaConversion('banner')
            ->performOnCollections('cover')
            ->fit(Fit::Crop, 1200, 675)
            ->format('webp');

        $this->addMediaConversion('gallery_thumb')
            ->performOnCollections('gallery')
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
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function searchNameOrNickname(Builder $query, string $search): void
    {
        $normalizedSearch = preg_replace('/\s+/u', ' ', trim($search)) ?? '';

        if ($normalizedSearch === '') {
            return;
        }

        $wildcardSearch = '%'.str_replace(' ', '%', $normalizedSearch).'%';
        $driverName = DB::connection($query->getModel()->getConnectionName())->getDriverName();
        $operator = $driverName === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $innerQuery) use ($normalizedSearch, $wildcardSearch, $operator): void {
            $innerQuery
                ->where('institutions.name', $operator, "%{$normalizedSearch}%")
                ->orWhere('institutions.name', $operator, $wildcardSearch);
            $innerQuery
                ->orWhere('institutions.nickname', $operator, "%{$normalizedSearch}%")
                ->orWhere('institutions.nickname', $operator, $wildcardSearch);
        });
    }

    /**
     * Stable pseudo-random directory order that stays pagination-safe for a day.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function publicDirectoryOrder(Builder $query): void
    {
        $offset = self::publicDirectoryOrderOffset(self::publicDirectorySessionSeed());
        $idExpression = $this->publicDirectoryOrderIdExpression($query);

        $query->orderByRaw("substr({$idExpression}, {$offset}, 32)")
            ->orderByRaw($idExpression.' asc');
    }

    public static function publicDirectoryOrderOffset(?string $sessionSeed = null, ?CarbonInterface $at = null): int
    {
        if (is_string($sessionSeed) && $sessionSeed !== '') {
            return (abs(crc32($sessionSeed)) % 24) + 1;
        }

        return (((int) ($at ?? now())->format('z')) % 24) + 1;
    }

    /**
     * @return array{primary: string, secondary: string}
     */
    public static function publicDirectorySortParts(string $institutionId, ?string $sessionSeed = null, ?CarbonInterface $at = null): array
    {
        $normalizedId = str_replace('-', '', $institutionId);
        $offset = self::publicDirectoryOrderOffset($sessionSeed, $at);

        return [
            'primary' => substr($normalizedId, $offset - 1),
            'secondary' => $normalizedId,
        ];
    }

    public static function publicDirectorySessionSeed(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $seed = $session->get(self::PUBLIC_DIRECTORY_SESSION_KEY);

        if (is_string($seed) && $seed !== '') {
            return $seed;
        }

        $seed = (string) Str::uuid();
        $session->put(self::PUBLIC_DIRECTORY_SESSION_KEY, $seed);

        return $seed;
    }

    /**
     * @param  Builder<self>  $query
     */
    private function publicDirectoryOrderIdExpression(Builder $query): string
    {
        $driver = DB::connection($query->getModel()->getConnectionName())->getDriverName();

        return $driver === 'pgsql'
            ? "replace(cast(institutions.id as text), '-', '')"
            : "replace(institutions.id, '-', '')";
    }
}
