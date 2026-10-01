<?php

namespace App\Models;

use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\CommerceSupport\Concerns\ParsesPostgresTimestamps;
use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Contacting\Concerns\HasSocialProfiles;
use AIArmada\Engagement\Contracts\Followable;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Events\Enums\FacilityAvailability;
use AIArmada\Events\Models\FacilityType;
use AIArmada\Events\Models\VenueFacility;
use AIArmada\Membership\Traits\HasMembers;
use App\Enums\InstitutionStatus;
use App\Enums\InstitutionType;
use App\Enums\InstitutionVenueRole;
use App\Enums\MemberSubjectType;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasDonationChannels;
use App\Models\Concerns\HasLanguages;
use App\Support\Institutions\InstitutionFacilities;
use Carbon\CarbonInterface;
use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property InstitutionStatus $status
 * @property CarbonInterface|null $inactive_at
 * @property CarbonInterface|null $stale_inactive_flagged_at
 * @property array<string, bool>|null $facilities
 * @property array<string, bool>|null $own_facilities
 * @property array<string, bool> $effective_facilities
 */
class Institution extends Model implements AuditableContract, Followable, HasMedia
{
    public const string PUBLIC_DIRECTORY_SESSION_KEY = 'public_institutions_directory_seed';

    /**
     * @use HasFactory<InstitutionFactory>
     * @use HasMembers<User>
     */
    use AuditsModelChanges, HasAddresses, HasContactMethods, HasDonationChannels, HasFactory, HasLanguages, HasMembers, HasSocialProfiles, HasUuids, InteractsWithMedia, KeepsDeletedModels, ParsesPostgresTimestamps, Searchable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'slug',
        'description',
        'has_friday_prayer_permission',
        'facilities',
        'source',
        'external_ref',
        'imported_at',

        'status',
        'verified_at',
        'verified_by',
        'rejected_at',
        'inactive_at',
        'stale_inactive_flagged_at',
        'last_state_change_at',
        'allow_public_event_submission',
        'public_submission_locked_at',
        'public_submission_locked_by',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => InstitutionType::class,
            'has_friday_prayer_permission' => 'boolean',
            'facilities' => 'array',
            'imported_at' => 'immutable_datetime',
            'status' => InstitutionStatus::class,
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'inactive_at' => 'immutable_datetime',
            'stale_inactive_flagged_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
            'allow_public_event_submission' => 'boolean',
            'public_submission_locked_at' => 'datetime',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (self $institution): void {
            if ($institution->exists) {
                // Create-only provenance: every existing row keeps its stored
                // identity bytes, so a manual row never acquires provenance
                // later and an imported row never changes identity.
                foreach (['source', 'external_ref', 'imported_at'] as $provenanceKey) {
                    if ($institution->isDirty($provenanceKey)) {
                        $institution->setAttribute($provenanceKey, $institution->getOriginal($provenanceKey));
                    }
                }
            } else {
                self::assertValidProvenancePair($institution);

                if ($institution->getAttribute('source') !== null && $institution->getAttribute('imported_at') === null) {
                    $institution->setAttribute('imported_at', now());
                }
            }

            if ($institution->isDirty('facilities')) {
                $normalized = InstitutionFacilities::normalize($institution->getAttribute('facilities'));

                $institution->setAttribute('facilities', $normalized === [] ? null : $normalized);
            }

            $rawStatus = $institution->getAttributes()['status'] ?? null;

            if ($rawStatus === null && ! $institution->exists) {
                $institution->setAttribute('status', InstitutionStatus::Pending);
                $rawStatus = InstitutionStatus::Pending->value;
            }

            $status = $rawStatus instanceof InstitutionStatus
                ? $rawStatus
                : InstitutionStatus::tryFrom((string) $rawStatus);

            if (! $status instanceof InstitutionStatus) {
                throw new InvalidArgumentException(sprintf('The institution status [%s] is invalid.', (string) $rawStatus));
            }

            if ($institution->isDirty('status')) {
                $now = now();

                if ($institution->exists) {
                    // Every real transition records its timestamp anew so a later
                    // inactivity cycle never reuses an older transition time.
                    $institution->last_state_change_at = $now;

                    match ($status) {
                        InstitutionStatus::Pending => null,
                        InstitutionStatus::Verified => $institution->verified_at = $now,
                        InstitutionStatus::Rejected => $institution->rejected_at = $now,
                        InstitutionStatus::Inactive => $institution->inactive_at = $now,
                    };
                } else {
                    // Creation preserves explicitly provided fixture timestamps.
                    $institution->last_state_change_at ??= $now;

                    match ($status) {
                        InstitutionStatus::Pending => null,
                        InstitutionStatus::Verified => $institution->verified_at ??= $now,
                        InstitutionStatus::Rejected => $institution->rejected_at ??= $now,
                        InstitutionStatus::Inactive => $institution->inactive_at ??= $now,
                    };
                }

                // Review flags belong to the latest inactivity cycle only.
                $institution->stale_inactive_flagged_at = null;

                if ($status === InstitutionStatus::Verified) {
                    $institution->verified_by ??= auth()->id();
                }
            }
        });
    }

    /**
     * Strict source-pair validation: each key is null or a non-empty string,
     * and both keys are set together. Identity bytes are never normalized.
     */
    private static function assertValidProvenancePair(self $institution): void
    {
        $source = $institution->getAttribute('source');
        $externalRef = $institution->getAttribute('external_ref');

        foreach (['source' => $source, 'external_ref' => $externalRef] as $key => $value) {
            if ($value !== null && (! is_string($value) || $value === '')) {
                throw new InvalidArgumentException("The institution {$key} must be a non-empty string or null.");
            }
        }

        if (($source !== null) !== ($externalRef !== null)) {
            throw new InvalidArgumentException('The institution source and external ref must be set together.');
        }
    }

    /**
     * Delete the row atomically with its snapshot, import exclusion, and
     * bridge links: ordinary Model::delete() opens no transaction of its own,
     * so without this wrapper a failed exclusion insert would leave the
     * institution deleted and unprotected.
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
     * Remove this institution's venue bridge rows only; linked venues,
     * spaces, and institutions are never touched.
     */
    private function deleteInstitutionVenueLinks(): void
    {
        InstitutionVenue::query()->where('institution_id', $this->getKey())->delete();
    }

    public function shouldBeSearchable(): bool
    {
        return in_array($this->status, InstitutionStatus::publiclyVisible(), true);
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged([
            'type',
            'name',
            'description',
            'slug',
            'status',
        ]);
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues());
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        if ($this->usesScoutDatabaseDriver()) {
            return $this->toScoutDatabaseSearchableArray();
        }

        $address = $this->primaryAddress();
        $type = $this->type;
        $updatedAt = $this->updated_at ?? now();

        return [
            'id' => (string) $this->getKey(),
            'type' => $type instanceof InstitutionType ? $type->value : (is_string($type) ? $type : null),
            'name' => (string) $this->name,
            'display_name' => $this->display_name,
            'nicknames' => $this->names->map(fn (InstitutionName $n): string => $n->full_name)->values()->all(),
            'description' => $this->searchableDescriptionText(),
            'search_text' => $this->searchableText(),
            'slug' => (string) $this->slug,
            'status' => $this->status->value,
            'country_code' => $address?->country_code,
            'city' => $address?->city,
            'state' => $address?->state,
            'postcode' => $address?->postcode,
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
            'names' => $this->names->map(fn (InstitutionName $n): string => $n->full_name)->values()->all(),
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
        return self::formatDisplayName($this->name, $this->primaryNickname);
    }

    public function followableName(): string
    {
        return $this->display_name;
    }

    public function followableUrl(): ?string
    {
        return route('institutions.show', ['institution' => $this->slug]);
    }

    public function followableImage(): ?string
    {
        return $this->public_logo_url;
    }

    public function defaultFollowNotificationLevel(): ?string
    {
        return null;
    }

    public function getPrimaryNicknameAttribute(): ?string
    {
        $primary = $this->names->firstWhere('is_primary', true);

        if ($primary instanceof InstitutionName) {
            return $primary->full_name;
        }

        return $this->names->first()?->full_name;
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
            ...$this->names->pluck('full_name')->all(),
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
            ->withPivot('capacity')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Venue, $this, InstitutionVenue, 'pivot'>
     */
    public function venues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'institution_venue')
            ->using(InstitutionVenue::class)
            ->withPivot(['id', 'role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Venue, $this, InstitutionVenue, 'pivot'>
     */
    public function operatedVenues(): BelongsToMany
    {
        return $this->venues()->wherePivot('role', InstitutionVenueRole::Operated->value);
    }

    /**
     * @return BelongsToMany<Venue, $this, InstitutionVenue, 'pivot'>
     */
    public function preferredVenues(): BelongsToMany
    {
        return $this->venues()->wherePivot('role', InstitutionVenueRole::Preferred->value);
    }

    /**
     * Own facilities flag map (code => bool), or null when unset.
     *
     * @return array<string, bool>|null
     */
    public function getOwnFacilitiesAttribute(): ?array
    {
        $raw = $this->getAttribute('facilities');

        if ($raw === null) {
            return null;
        }

        return InstitutionFacilities::normalize($raw);
    }

    /**
     * Effective facilities flag map (code => true).
     *
     * Merges public/available facilities from linked operated venues and
     * linked active spaces; explicit own flags win (false removes). Only
     * active, publicly visible operated venues and linked spaces
     * contribute; preferred venues never inherit.
     *
     * @return array<string, bool>
     */
    public function getEffectiveFacilitiesAttribute(): array
    {
        $this->loadMissing(['operatedVenues.facilities.facilityType', 'spaces.facilities.facilityType']);

        $merged = [];

        foreach ($this->operatedVenues as $venue) {
            if (! in_array($venue->getAttribute('status'), ['verified', 'pending'], true)) {
                continue;
            }

            if ($venue->getAttribute('visibility') !== 'public') {
                continue;
            }

            foreach ($venue->facilities as $facility) {
                if ($facility->venue_space_id !== null) {
                    continue;
                }

                $code = $this->publicAvailableFacilityCode($facility);

                if ($code !== null) {
                    $merged[$code] = true;
                }
            }
        }

        foreach ($this->spaces as $space) {
            if ((string) $space->getAttribute('status') !== 'active') {
                continue;
            }

            if ($space->getAttribute('visibility') !== 'public') {
                continue;
            }

            foreach ($space->facilities as $facility) {
                $code = $this->publicAvailableFacilityCode($facility);

                if ($code !== null) {
                    $merged[$code] = true;
                }
            }
        }

        foreach ($this->own_facilities ?? [] as $code => $enabled) {
            if ($enabled) {
                $merged[$code] = true;
            } else {
                unset($merged[$code]);
            }
        }

        return $merged;
    }

    private function publicAvailableFacilityCode(VenueFacility $facility): ?string
    {
        if ($facility->availability !== FacilityAvailability::Available || $facility->visibility !== 'public') {
            return null;
        }

        $type = $facility->facilityType;

        if (! $type instanceof FacilityType || ! $type->is_active) {
            return null;
        }

        $code = trim((string) $type->code);

        return $code !== '' ? $code : null;
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<InstitutionName, $this>
     */
    public function names(): HasMany
    {
        return $this->hasMany(InstitutionName::class, 'institution_id');
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'affiliations', 'institution_id', 'affiliatable_id')
            ->wherePivot('affiliatable_type', (new Person)->getMorphClass())
            ->withPivot(['position', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
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
            ->fit(Fit::Max, 1080, 1080)
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
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues());
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

        $phraseSearch = "%{$normalizedSearch}%";
        $wildcardSearch = '%'.str_replace(' ', '%', $normalizedSearch).'%';
        $query->where(function (Builder $innerQuery) use ($phraseSearch, $wildcardSearch): void {
            $innerQuery->whereLike('institutions.name', $phraseSearch);

            // Single-word searches produce identical phrase and wildcard
            // patterns; repeating the predicate only doubles the filter work.
            if ($wildcardSearch !== $phraseSearch) {
                $innerQuery->orWhereLike('institutions.name', $wildcardSearch);
            }

            $innerQuery->orWhereHas('names', function (Builder $nameQuery) use ($phraseSearch, $wildcardSearch): Builder {
                $nameQuery->whereLike('full_name', $phraseSearch);

                if ($wildcardSearch !== $phraseSearch) {
                    $nameQuery->orWhereLike('full_name', $wildcardSearch);
                }

                return $nameQuery;
            });
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
