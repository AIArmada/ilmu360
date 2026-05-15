<?php

namespace App\Models;

use App\Enums\EventKeyPersonRole;
use App\Enums\Honorific;
use App\Enums\MemberSubjectType;
use App\Enums\PostNominal;
use App\Enums\PreNominal;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasAddress;
use App\Models\Concerns\HasContacts;
use App\Models\Concerns\HasDonationChannels;
use App\Models\Concerns\HasFollowers;
use App\Models\Concerns\HasLanguages;
use App\Models\Concerns\HasSocialMedia;
use Carbon\CarbonInterface;
use Database\Factories\SpeakerFactory;
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

/**
 * @property array<int, mixed>|null $honorific
 * @property array<int, mixed>|null $pre_nominal
 * @property array<int, string>|string|null $post_nominal
 * @property array<int, array<string, mixed>>|null $qualifications
 */
class Speaker extends Model implements AuditableContract, HasMedia
{
    public const string PUBLIC_DIRECTORY_SESSION_KEY = 'public_speakers_directory_seed';

    /** @use HasFactory<SpeakerFactory> */
    use AuditsModelChanges, HasAddress, HasContacts, HasDonationChannels, HasFactory, HasFollowers, HasLanguages, HasSocialMedia, HasUuids, InteractsWithMedia, KeepsDeletedModels, Searchable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'gender',
        'honorific',
        'pre_nominal',
        'post_nominal',
        'slug',
        'bio',
        'status',
        'qualifications',
        'is_freelance',
        'job_title',
        'is_active',
        'allow_public_event_submission',
        'public_submission_locked_at',
        'public_submission_locked_by',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'honorific' => 'array',
            'pre_nominal' => 'array',
            'post_nominal' => 'array',
            'bio' => 'array',
            'qualifications' => 'array',
            'is_freelance' => 'boolean',
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
            'name',
            'honorific',
            'pre_nominal',
            'post_nominal',
            'job_title',
            'slug',
            'status',
            'is_active',
            'gender',
        ]);
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query
            ->with('address')
            ->where('speakers.is_active', true)
            ->whereIn('speakers.status', ['verified', 'pending']);
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
        $updatedAt = $this->updated_at ?? now();

        return [
            'id' => (string) $this->getKey(),
            'name' => (string) $this->name,
            'formatted_name' => $this->formatted_name,
            'search_text' => $this->searchableText(),
            'job_title' => filled($this->job_title) ? (string) $this->job_title : null,
            'slug' => (string) $this->slug,
            'status' => (string) $this->status,
            'is_active' => (bool) $this->is_active,
            'gender' => filled($this->gender) ? (string) $this->gender : null,
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
            'searchable_name' => filled($this->searchable_name) ? (string) $this->searchable_name : null,
            'job_title' => filled($this->job_title) ? (string) $this->job_title : null,
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
        static::saving(function (Speaker $speaker) {
            if ($speaker->status === 'rejected') {
                $speaker->is_active = false;
            }

            if ($speaker->isDirty('qualifications')) {
                $qualifications = $speaker->qualifications;
                $allowedPostNominals = array_map(
                    static fn (PostNominal $postNominal): string => $postNominal->value,
                    PostNominal::cases()
                );
                $explicitPostNominals = is_array($speaker->post_nominal)
                    ? array_values(array_filter($speaker->post_nominal, static fn (string $value): bool => $value !== ''))
                    : [];

                if (! is_array($qualifications) || $qualifications === []) {
                    $speaker->post_nominal = $explicitPostNominals !== [] ? $explicitPostNominals : null;

                    return;
                }

                $parts = [];

                foreach ($qualifications as $qualification) {
                    if (! is_array($qualification)) {
                        continue;
                    }

                    $degree = $qualification['degree'] ?? null;

                    if (is_string($degree) && in_array($degree, $allowedPostNominals, true)) {
                        $parts[] = $degree;
                    }
                }

                $parts = array_values(array_unique($parts));
                $speaker->post_nominal = $parts !== [] ? $parts : null;
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
                return $avatarMedia->getAvailableUrl(['profile', 'thumb']) ?: $avatarMedia->getUrl();
            }
        }

        return $this->default_avatar_url;
    }

    public function getDefaultAvatarUrlAttribute(): string
    {
        if ($this->avatar_url) {
            return $this->avatar_url;
        }

        if ($this->gender === 'female') {
            return asset('images/placeholders/speaker-female.png');
        }

        return asset('images/placeholders/speaker-male.png');
    }

    public function getFormattedNameAttribute(): string
    {
        return self::formatDisplayedName(
            $this->name,
            $this->honorific,
            $this->pre_nominal,
            $this->post_nominal,
        );
    }

    /**
     * @param  iterable<int, mixed>|string|null  $honorific
     * @param  iterable<int, mixed>|string|null  $preNominal
     * @param  iterable<int, mixed>|string|null  $postNominal
     */
    public static function formatDisplayedName(
        ?string $name,
        iterable|string|null $honorific = null,
        iterable|string|null $preNominal = null,
        iterable|string|null $postNominal = null,
    ): string {
        // Public speaker pages follow public-profile naming, not formal salutation format:
        // professor-rank titles lead, honorifics follow, then the remaining prefixes.
        $leadingPreNominalLabels = self::labelsFromPreNominalCases(self::leadingPreNominalCases($preNominal));
        $honorificLabels = self::labelsFromHonorificCases(self::orderedHonorificCases($honorific));
        $trailingPreNominalLabels = self::labelsFromPreNominalCases(self::trailingPreNominalCases($preNominal));

        $parts = array_filter([
            $leadingPreNominalLabels,
            $honorificLabels,
            $trailingPreNominalLabels,
            trim((string) $name),
        ], filled(...));

        $formatted = trim(implode(' ', $parts));
        $postNominalValues = self::orderedPostNominalValues($postNominal);

        if ($postNominalValues !== []) {
            $formatted = trim($formatted.', '.implode(', ', $postNominalValues));
        }

        return $formatted;
    }

    private function searchableText(): string
    {
        $rawDecorations = collect([
            ...self::normalizedStringValues($this->honorific),
            ...self::normalizedStringValues($this->pre_nominal),
            ...self::normalizedStringValues($this->post_nominal),
        ])
            ->map(static fn (string $value): string => str_replace(['_', '-'], ' ', $value))
            ->implode(' ');

        return trim(implode(' ', array_filter([
            trim($this->formatted_name),
            trim((string) $this->name),
            trim($rawDecorations),
            trim((string) $this->job_title),
        ])));
    }

    /**
     * @param  iterable<int, mixed>|string|null  $values
     * @return list<Honorific>
     */
    private static function orderedHonorificCases(iterable|string|null $values): array
    {
        $cases = [];

        foreach (self::normalizedStringValues($values) as $value) {
            $case = Honorific::tryFrom($value);

            if ($case instanceof Honorific) {
                $cases[$case->value] = $case;
            }
        }

        $orderedCases = array_values($cases);

        usort($orderedCases, static fn (Honorific $left, Honorific $right): int => self::honorificSortOrder($left) <=> self::honorificSortOrder($right));

        return $orderedCases;
    }

    /**
     * @param  iterable<int, mixed>|string|null  $values
     * @return list<PreNominal>
     */
    private static function orderedPreNominalCases(iterable|string|null $values): array
    {
        $cases = [];

        foreach (self::normalizedStringValues($values) as $value) {
            $case = PreNominal::tryFrom($value);

            if ($case instanceof PreNominal) {
                $cases[$case->value] = $case;
            }
        }

        $orderedCases = array_values($cases);

        usort($orderedCases, static fn (PreNominal $left, PreNominal $right): int => self::preNominalSortOrder($left) <=> self::preNominalSortOrder($right));

        return $orderedCases;
    }

    /**
     * @param  iterable<int, mixed>|string|null  $values
     * @return list<PreNominal>
     */
    private static function leadingPreNominalCases(iterable|string|null $values): array
    {
        return array_values(array_filter(
            self::orderedPreNominalCases($values),
            static fn (PreNominal $case): bool => in_array($case, [PreNominal::Prof, PreNominal::ProfMadya], true),
        ));
    }

    /**
     * @param  iterable<int, mixed>|string|null  $values
     * @return list<PreNominal>
     */
    private static function trailingPreNominalCases(iterable|string|null $values): array
    {
        return array_values(array_filter(
            self::orderedPreNominalCases($values),
            static fn (PreNominal $case): bool => ! in_array($case, [PreNominal::Prof, PreNominal::ProfMadya], true),
        ));
    }

    /**
     * @param  list<Honorific>  $cases
     */
    private static function labelsFromHonorificCases(array $cases): ?string
    {
        if ($cases === []) {
            return null;
        }

        return collect($cases)
            ->map(static fn (Honorific $case): string => $case->getLabel())
            ->implode(' ');
    }

    /**
     * @param  list<PreNominal>  $cases
     */
    private static function labelsFromPreNominalCases(array $cases): ?string
    {
        if ($cases === []) {
            return null;
        }

        return collect($cases)
            ->map(static fn (PreNominal $case): string => $case->getLabel())
            ->implode(' ');
    }

    /**
     * @param  iterable<int, mixed>|string|null  $values
     * @return list<string>
     */
    private static function orderedPostNominalValues(iterable|string|null $values): array
    {
        $uniqueValues = [];

        foreach (self::normalizedStringValues($values) as $value) {
            $uniqueValues[$value] = $value;
        }

        $sortableValues = [];

        foreach (array_values($uniqueValues) as $index => $value) {
            $sortableValues[] = [
                'value' => self::postNominalDisplayValue($value),
                'order' => self::postNominalSortOrder($value),
                'index' => $index,
            ];
        }

        usort($sortableValues, static function (array $left, array $right): int {
            $orderComparison = $left['order'] <=> $right['order'];

            if ($orderComparison !== 0) {
                return $orderComparison;
            }

            return $left['index'] <=> $right['index'];
        });

        return array_values(array_map(
            static fn (array $entry): string => $entry['value'],
            $sortableValues,
        ));
    }

    private static function honorificSortOrder(Honorific $honorific): int
    {
        return match ($honorific) {
            Honorific::Tun,
            Honorific::TohPuan => 10,
            Honorific::TanSri,
            Honorific::PuanSri => 20,
            Honorific::DatukSeriUtama => 30,
            Honorific::DatukPatinggi => 40,
            Honorific::DatukAmar => 50,
            Honorific::DatukSeriPanglima => 60,
            Honorific::DatukSeri,
            Honorific::DatoSri,
            Honorific::DatukPaduka,
            Honorific::DatinPaduka => 70,
            Honorific::DatukWira,
            Honorific::DatoWira,
            Honorific::DatoSetia => 80,
            Honorific::Datuk,
            Honorific::Dato,
            Honorific::Datin => 90,
        };
    }

    private static function preNominalSortOrder(PreNominal $preNominal): int
    {
        return match ($preNominal) {
            PreNominal::Prof => 10,
            PreNominal::Syeikh => 20,
            PreNominal::SyeikhulMaqari => 21,
            PreNominal::Maulana => 22,
            PreNominal::Habib => 23,
            PreNominal::TuanGuru => 24,
            PreNominal::Pendeta => 25,
            PreNominal::Ustaz => 26,
            PreNominal::Ustazah => 27,
            PreNominal::ImamMuda => 28,
            PreNominal::PU,
            PreNominal::Dai => 29,
            PreNominal::Hafiz => 30,
            PreNominal::Hafizah => 31,
            PreNominal::Qari => 32,
            PreNominal::Qariah => 33,
            PreNominal::Mufti => 34,
            PreNominal::Kadi => 35,
            PreNominal::ProfMadya => 40,
            PreNominal::Ir => 50,
            PreNominal::Ar => 51,
            PreNominal::Dr => 60,
            PreNominal::Hj => 61,
            PreNominal::Hjh => 62,
        };
    }

    private static function postNominalSortOrder(string $value): int
    {
        $case = PostNominal::tryFrom($value);

        return match ($case) {
            PostNominal::PhD => 10,
            PostNominal::MSc => 20,
            PostNominal::MA => 21,
            PostNominal::BSc => 30,
            PostNominal::BA => 31,
            PostNominal::Lc => 32,
            PostNominal::Hons => 33,
            PostNominal::Dpl => 40,
            null => 1_000,
        };
    }

    private static function postNominalDisplayValue(string $value): string
    {
        return PostNominal::tryFrom($value)?->getLabel() ?? $value;
    }

    /**
     * @param  iterable<int, mixed>|string|null  $values
     * @return list<string>
     */
    private static function normalizedStringValues(iterable|string|null $values): array
    {
        if (is_string($values)) {
            $trimmed = trim($values);

            return $trimmed !== '' ? [$trimmed] : [];
        }

        if (! is_iterable($values)) {
            return [];
        }

        return collect($values)
            ->map(static function (mixed $value): ?string {
                if ($value instanceof \BackedEnum) {
                    $value = $value->value;
                }

                if (! is_string($value)) {
                    return null;
                }

                $trimmed = trim($value);

                return $trimmed !== '' ? $trimmed : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Generic key-person link across all event roles.
     *
     * Prefer speakerEvents() for talk history and nonSpeakerEventKeyPeople()
     * when role-specific assignment matters.
     *
     * @return BelongsToMany<Event, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_key_people', 'speaker_id', 'event_id')
            ->using(EventKeyPersonPivot::class)
            ->withPivot(['id', 'role', 'name', 'order_column', 'is_public', 'notes'])
            ->withTimestamps()
            ->orderByPivot('order_column');
    }

    /**
     * @return BelongsToMany<Event, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function speakerEvents(): BelongsToMany
    {
        return $this->events()
            ->wherePivot('role', EventKeyPersonRole::Speaker->value)
            ->withPivotValue('role', EventKeyPersonRole::Speaker->value);
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function eventKeyPeople(): HasMany
    {
        return $this->hasMany(EventKeyPerson::class);
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function nonSpeakerEventKeyPeople(): HasMany
    {
        return $this->eventKeyPeople()
            ->where('role', '!=', EventKeyPersonRole::Speaker->value)
            ->where('is_public', true)
            ->orderBy('order_column');
    }

    /**
     * @return BelongsToMany<Institution, $this>
     */
    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'institution_speaker')
            ->withPivot(['position', 'is_primary', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'speaker_user')
            ->withTimestamps();
    }

    /**
     * @return HasMany<MemberInvitation, $this>
     */
    public function memberInvitations(): HasMany
    {
        return $this->hasMany(MemberInvitation::class, 'subject_id')
            ->where('subject_type', MemberSubjectType::Speaker->value);
    }

    /**
     * @return HasMany<MembershipClaim, $this>
     */
    public function membershipClaims(): HasMany
    {
        return $this->hasMany(MembershipClaim::class, 'subject_id')
            ->where('subject_type', MemberSubjectType::Speaker->value);
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
        $this->addMediaCollection('avatar')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/speaker.png'))
            ->singleFile();

        $this->addMediaCollection('cover')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/speaker.png'))
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
            ->performOnCollections('avatar')
            ->width(80)
            ->height(80)
            ->sharpen(10)
            ->format('webp');

        $this->addMediaConversion('profile')
            ->performOnCollections('avatar')
            ->width(400)
            ->height(400)
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
     * Scope a query to only include active speakers.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Stable pseudo-random directory order that stays pagination-safe for a day.
     *
     * @param  Builder<self>  $query
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
    public static function publicDirectorySortParts(string $speakerId, ?string $sessionSeed = null, ?CarbonInterface $at = null): array
    {
        $normalizedId = str_replace('-', '', $speakerId);
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
            ? "replace(cast(speakers.id as text), '-', '')"
            : "replace(speakers.id, '-', '')";
    }
}
