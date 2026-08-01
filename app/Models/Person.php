<?php

namespace App\Models;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Contacting\Concerns\HasSocialProfiles;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Membership\Models\MembershipInvitation;
use AIArmada\Membership\Traits\HasMembers;
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Models\CredentialAssignment;
use AIArmada\Persons\Models\PersonName;
use AIArmada\Persons\Models\TitleAssignment;
use App\Enums\EventKeyPersonRole;
use App\Enums\SpeakerStatus;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasDonationChannels;
use App\Models\Concerns\HasLanguages;
use App\Support\Search\PersonSearchService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property bool $allow_public_event_submission
 * @property CarbonImmutable|null $last_state_change_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $verified_by
 * @property Carbon|null $updated_at
 */
class Person extends \AIArmada\Persons\Models\Person implements AuditableContract, HasMedia
{
    public const string PUBLIC_DIRECTORY_SESSION_KEY = 'public_persons_directory_seed';

    /** @use HasMembers<User> */
    use AuditsModelChanges,
        HasAddresses,
        HasContactMethods,
        HasDonationChannels,
        HasLanguages,
        HasMembers,
        HasSocialProfiles,
        InteractsWithMedia,
        KeepsDeletedModels,
        Searchable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'family_name',
        'middle_name',
        'gender',
        'date_of_birth',
        'nationality_country_id',
        'slug',
        'searchable_name',
        'bio',
        'status',
        'verified_at',
        'verified_by',
        'rejected_at',
        'last_state_change_at',
        'published_at',
        'speaker_status',
        'allow_public_event_submission',
        'public_submission_locked_at',
        'public_submission_locked_by',
    ];

    #[\Override]
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
            'speaker_status' => SpeakerStatus::class,
            'allow_public_event_submission' => 'boolean',
            'public_submission_locked_at' => 'datetime',
        ]);
    }

    public static function formatDisplayedName(
        string $name,
        ?string $middleName = null,
        ?string $familyName = null,
    ): string {
        return trim(implode(' ', array_filter([
            trim($name),
            trim((string) $middleName),
            trim((string) $familyName),
        ])));
    }

    public function getFormattedNameAttribute(): string
    {
        $formattedName = parent::getFormattedNameAttribute();
        $middleName = trim((string) $this->middle_name);
        $familyName = trim((string) $this->family_name);

        if ($middleName === '' && $familyName === '') {
            return $formattedName;
        }

        $nameWithoutPostNominals = trim(Str::before($formattedName, ','));
        $formattedBase = trim(implode(' ', array_filter([
            $nameWithoutPostNominals,
            $middleName,
            $familyName,
        ])));

        return Str::contains($formattedName, ',')
            ? $formattedBase.', '.Str::after($formattedName, ', ')
            : $formattedBase;
    }

    public function shouldBeSearchable(): bool
    {
        return in_array((string) $this->status, ['verified', 'pending'], true);
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged([
            'name',
            'middle_name',
            'family_name',
            'slug',
            'status',
            'gender',
        ]);
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query
            ->whereIn('persons.status', ['verified', 'pending']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        if ($this->usesScoutDatabaseDriver()) {
            return $this->toScoutDatabaseSearchableArray();
        }

        $alternativeNames = $this->relationLoaded('names')
            ? $this->getRelation('names')->pluck('full_name')->all()
            : $this->names()->pluck('full_name')->all();
        $searchableText = app(PersonSearchService::class)
            ->buildSearchableText($this);
        $address = $this->primaryAddress();
        $updatedAt = $this->updated_at ?? now();

        return [
            'id' => (string) $this->getKey(),
            'name' => (string) $this->name,
            'middle_name' => $this->middle_name,
            'family_name' => $this->family_name,
            'formatted_name' => $this->formatted_name,
            'person_names' => implode(' ', $alternativeNames),
            'search_text' => $searchableText,
            'slug' => (string) $this->slug,
            'status' => (string) $this->status,
            'gender' => $this->gender instanceof Gender ? $this->gender->value : $this->gender,
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
            'searchable_name' => app(PersonSearchService::class)
                ->buildSearchableText($this),
            'slug' => (string) $this->slug,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function usesScoutDatabaseDriver(): bool
    {
        return (string) config('scout.driver') === 'database';
    }

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (Person $person) {
            if ($person->isDirty('status')) {
                $now = now();
                $person->last_state_change_at = $now;

                match ((string) $person->status) {
                    'verified' => $person->verified_at ??= $now,
                    'rejected' => $person->rejected_at ??= $now,
                    'inactive' => $person->published_at ??= $now,
                    default => null,
                };

                if ((string) $person->status === 'verified') {
                    $person->verified_by ??= auth()->id();
                }
            }
        });
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->hasMedia('avatar')) {
            return $this->getFirstMediaUrl('avatar', 'thumb');
        }

        return null;
    }

    public function getPublicAvatarUrlAttribute(): string
    {
        if ($this->hasMedia('avatar')) {
            $avatarMedia = $this->getFirstMedia('avatar');

            if ($avatarMedia instanceof Media) {
                return $avatarMedia->getAvailableUrl(['thumb']) ?: $avatarMedia->getUrl();
            }
        }

        return $this->default_avatar_url;
    }

    public function getPublicMainUrlAttribute(): string
    {
        if ($this->hasMedia('profile')) {
            $profileMedia = $this->getFirstMedia('profile');

            if ($profileMedia instanceof Media) {
                return $profileMedia->getAvailableUrl(['profile_thumb']) ?: $profileMedia->getUrl();
            }
        }

        return asset('images/placeholders/person.png');
    }

    public function getDefaultAvatarUrlAttribute(): string
    {
        if ($this->avatar_url) {
            return $this->avatar_url;
        }

        if ($this->gender === Gender::Female) {
            return asset('images/placeholders/person-female.png');
        }

        return asset('images/placeholders/person-male.png');
    }

    /**
     * Generic key-person link across all event roles.
     *
     * Prefer personEvents() for talk history and nonSpeakerEventKeyPeople()
     * when role-specific assignment matters.
     *
     * @return BelongsToMany<Event, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_involvements', 'involveable_id', 'event_id')
            ->using(EventKeyPersonPivot::class)
            ->wherePivot('involveable_type', 'person')
            ->withPivot(['id', 'involveable_type', 'role_code', 'sort_order', 'notes'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return BelongsToMany<Event, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function personEvents(): BelongsToMany
    {
        return $this->events()
            ->wherePivot('role_code', EventKeyPersonRole::Speaker->value)
            ->withPivotValue('role_code', EventKeyPersonRole::Speaker->value);
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function eventKeyPeople(): HasMany
    {
        return $this->hasMany(EventKeyPerson::class, 'involveable_id')
            ->where('involveable_type', 'person');
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function nonSpeakerEventKeyPeople(): HasMany
    {
        return $this->eventKeyPeople()
            ->where('role_code', '!=', EventKeyPersonRole::Speaker->value)
            ->where('visibility', 'public')
            ->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'entity');
    }

    /**
     * @return MorphMany<TitleAssignment, $this>
     */
    #[\Override]
    public function titleAssignments(): MorphMany
    {
        return $this->morphMany(TitleAssignment::class, 'titleable');
    }

    /**
     * @return MorphMany<MembershipInvitation, $this>
     */
    public function memberInvitations(): MorphMany
    {
        return $this->invitations();
    }

    /**
     * @return MorphMany<CredentialAssignment, $this>
     */
    #[\Override]
    public function credentialAssignments(): MorphMany
    {
        return $this->morphMany(CredentialAssignment::class, 'credentialable');
    }

    /**
     * @return MorphToMany<Institution, $this>
     */
    public function institutions(): MorphToMany
    {
        return $this->morphToMany(Institution::class, 'affiliatable', 'affiliations')
            ->withPivot(['id', 'position', 'is_primary', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<PersonName, $this>
     */
    #[\Override]
    public function names(): HasMany
    {
        return $this->hasMany(PersonName::class, 'person_id');
    }

    /**
     * @return BelongsTo<AddressCountry, $this>
     */
    public function nationality(): BelongsTo
    {
        return $this->belongsTo(AddressCountry::class, 'nationality_country_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/person.png'))
            ->singleFile();

        $this->addMediaCollection('main')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/person.png'))
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('cover')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/person.png'))
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('gallery')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->withResponsiveImages();

        $this->addMediaCollection('profile')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/person.png'))
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('documents')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('certificates')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('avatar')
            ->width(1080)
            ->height(1080)
            ->sharpen(10)
            ->format('webp');

        $this->addMediaConversion('profile_thumb')
            ->performOnCollections('profile')
            ->fit(Fit::Crop, 1080, 1440)
            ->format('webp');

        $this->addMediaConversion('thumb')
            ->performOnCollections('main')
            ->width(1080)
            ->height(1080)
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
     * @param  Builder<Person>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', ['verified', 'pending']);
    }

    /**
     * @param  Builder<Person>  $query
     */
    #[Scope]
    protected function speakers(Builder $query): void
    {
        $query->whereNotNull('speaker_status');
    }

    /**
     * @param  Builder<Person>  $query
     */
    #[Scope]
    protected function publicDirectoryOrder(Builder $query, ?string $sessionSeed = null): void
    {
        $offset = self::publicDirectoryOrderOffset($sessionSeed ?? self::publicDirectorySessionSeed());
        $idExpression = self::publicDirectoryOrderIdExpression($query);

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
    public static function publicDirectorySortParts(string $personId, ?string $sessionSeed = null, ?CarbonInterface $at = null): array
    {
        $normalizedId = str_replace('-', '', $personId);
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
            ? "replace(cast(persons.id as text), '-', '')"
            : "replace(persons.id, '-', '')";
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
