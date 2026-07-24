<?php

namespace App\Models;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Response;
use AIArmada\Engagement\Traits\HasResponses;
use AIArmada\Events\Enums\RegistrationMode as PackageRegistrationMode;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventAttribute;
use AIArmada\Events\Models\EventAudience;
use AIArmada\Events\Models\EventAudienceProfile;
use AIArmada\Events\Models\EventClassification;
use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventLanguage;
use AIArmada\Events\Models\EventLink;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventReference;
use AIArmada\Events\Models\EventRole;
use AIArmada\Events\Models\EventSeriesItemPivot;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Membership\Traits\HasMembers;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventChangeType;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\MemberSubjectType;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\ReferenceType;
use App\Enums\TimingMode;
use App\Models\Builders\EventBuilder;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasDonationChannels;
use App\States\EventStatus\EventStatus;
use App\States\EventStatus\Pending;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Timezone\UserDateTimeFormatter;
use BackedEnum;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Nnjeim\World\Models\Language;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\ModelStates\HasStates;

/**
 * App Event subclass: package Event + intentional product projections.
 *
 * Package-backed schedule/links/audience/flags/location are projected as flat
 * form attributes and written to their canonical package relations.
 *
 * @property string $id
 * @property string|null $institution_id
 * @property string|null $default_venue_id
 * @property string $title
 * @property string $slug
 * @property array<string, mixed>|string|null $description
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $timezone
 * @property EventStatus|string $status
 * @property string|null $schedule_kind
 * @property EventVisibility|string|null $visibility
 * @property string|null $delivery_mode
 * @property TimingMode|string|null $timing_mode
 * @property PrayerReference|string|null $prayer_reference
 * @property PrayerOffset|string|null $prayer_offset
 * @property string|null $prayer_display_text
 * @property EventGenderRestriction|string|null $gender
 * @property Collection<int, EventAgeGroup>|array<int, string>|null $age_group
 * @property list<string> $event_category_ids
 * @property bool|null $children_allowed
 * @property string|null $live_url
 * @property string|null $event_url
 * @property string|null $recording_url
 * @property Carbon|null $published_at
 * @property array<string, mixed>|null $metadata
 * @property bool|null $is_featured
 * @property bool|null $is_muslim_only
 * @property-read Institution|null $institution
 * @property-read Institution|Person|null $organizer
 * @property-read Venue|null $venue
 * @property-read EventChangeAnnouncement|null $latestPublishedChangeAnnouncement
 * @property-read EventChangeAnnouncement|null $latestPublishedReplacementAnnouncement
 * @property-read string|null $reference_study_subtitle
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventKeyPerson> $keyPeople
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventInvolvement> $involvements
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventOccurrence> $occurrences
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Reference> $references
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Person> $persons
 * @property Carbon|null $updated_at
 * @property Carbon|null $created_at
 */
class Event extends PackageEvent implements AuditableContract
{
    /**
     * @use HasFactory<EventFactory>
     * @use HasMembers<User>
     */
    use AuditsModelChanges, HasAddresses, HasDonationChannels, HasFactory, HasMembers, HasResponses, HasStates, KeepsDeletedModels, Searchable;

    protected static string $ownerScopeConfigKey = '';

    protected static bool $ownerScopeEnabledByDefault = false;

    /**
     * Statuses visible on public listings and detail pages.
     *
     * @var list<string>
     */
    public const array PUBLIC_STATUSES = ['approved', 'pending', 'cancelled'];

    /**
     * Statuses that still allow engagement actions (save/going).
     *
     * @var list<string>
     */
    public const array ENGAGEABLE_STATUSES = ['approved', 'pending'];

    public function isRegistrationAvailable(): bool
    {
        if (! in_array((string) $this->status, self::ENGAGEABLE_STATUSES, true)) {
            return false;
        }

        if ($this->visibility === EventVisibility::Unlisted) {
            return true;
        }

        return $this->visibility === EventVisibility::Public
            && $this->published_at !== null;
    }

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array{width: int, height: int}|null
     */
    private ?array $resolvedPosterDimensions = null;

    /**
     * @var Collection<int, Language>|null
     */
    private ?Collection $resolvedLanguageCache = null;

    /**
     * @var array<string, string|null>
     */
    private array $pendingLinkWrites = [];

    /**
     * @var array<string, mixed>
     */
    private array $pendingAudienceWrites = [];

    /**
     * @var array<string, mixed>
     */
    private array $pendingAudienceProfileWrites = [];

    /**
     * @var array<string, mixed>
     */
    private array $pendingAttributeWrites = [];

    /** @var list<string> */
    private array $pendingCategoryIds = [];

    #[\Override]
    protected static function booted(): void
    {
        static::saved(function (Event $event): void {
            $event->syncUrlLinks();
            $event->syncAudiences();
            $event->syncAttributes();
        });

        static::deleting(function (Event $event) {
            $event->members()->detach();
            $event->involvements()->delete();
            $event->accessPolicies()->delete();
            $event->keyPeople()->delete();
            $event->eventReferences()->delete();
            $event->savedBy()->delete();
            $event->goingBy()->delete();

            $event->registrations()->each(function ($registration): void {
                $registration->delete();
            });
            $event->checkins()->each(function (EventCheckin $checkin): void {
                $checkin->delete();
            });
            $event->submissions()->each(function (EventSubmission $submission): void {
                $submission->delete();
            });
            $event->moderationReviews()->each(function (ModerationReview $review): void {
                OwnerContext::withOwner(null, fn () => $review->delete());
            });
            $event->changeAnnouncements()->each(function (EventChangeAnnouncement $announcement): void {
                $announcement->delete();
            });
            $event->mediaLinks()->each(function (MediaLink $mediaLink): void {
                $mediaLink->delete();
            });

            // Note: MediaLibrary works automatically via InteractsWithMedia if we delete the model,
            // but we can also be explicit if needed.
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'institution_id',

        'title',
        'slug',
        'description',
        'schedule_kind',
        'timezone',
        'live_url',
        'event_url',
        'recording_url',
        'gender',
        'age_group',
        'children_allowed',
        'visibility',
        'status',
        'published_at',
        'cancelled_at',
        'last_state_change_at',
        'summary',
        'delivery_mode',
        'default_venue_id',
        'pricing_mode',
        'registration_mode',
        'issue_passes_for_free',
        'metadata',
        'is_featured',
        'is_muslim_only',
    ];

    #[\Override]
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => EventStatus::class,
            'visibility' => EventVisibility::class,
            'published_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
            'description' => 'array',
            'metadata' => 'array',
            'issue_passes_for_free' => 'boolean',
        ]);
    }

    #[\Override]
    public function newEloquentBuilder($query): EventBuilder
    {
        return new EventBuilder($query);
    }

    #[\Override]
    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }

    #[\Override]
    public function setAttribute($key, $value): mixed
    {
        if ($key === 'event_category_ids') {
            $this->pendingCategoryIds = is_array($value)
                ? array_values(array_map(strval(...), $value))
                : [];

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    #[\Override]
    public function getAttribute($key): mixed
    {
        if (in_array($key, ['starts_at', 'ends_at'], true)) {
            return $this->primaryOccurrenceDate($key);
        }

        if ($key === 'event_category_ids') {
            if ($this->pendingCategoryIds !== []) {
                return $this->pendingCategoryIds;
            }

            return $this->categoryClassifications()
                ->pluck('event_term_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        if ($key === 'languages') {
            return $this->resolvedLanguages();
        }

        if ($key === 'organizer') {
            return OwnerContext::withOwner(null, fn () => $this->primaryOrganizerInvolvement?->involveable);
        }

        return parent::getAttribute($key);
    }

    /**
     * @return HasMany<EventOccurrence, $this>
     */
    #[\Override]
    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class)
            ->orderBy('starts_at')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return HasOne<EventOccurrence, $this>
     */
    #[\Override]
    public function primaryOccurrence(): HasOne
    {
        return $this->hasOne(EventOccurrence::class)
            ->orderBy('starts_at')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Event-level expressions are distinct from occurrence/session expressions.
     * The latter must never be addressed through this relation.
     *
     * @return HasMany<EventTimeExpression, $this>
     */
    #[\Override]
    public function timeExpressions(): HasMany
    {
        return $this->hasMany(EventTimeExpression::class)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id');
    }

    /**
     * @return HasMany<EventLanguage, $this>
     */
    public function languageRecords(): HasMany
    {
        return parent::languages()->select(['*']);
    }

    /**
     * @param  array<int>|int  $languages
     */
    public function syncLanguages(array|int $languages): void
    {
        $languageIds = collect(is_array($languages) ? $languages : [$languages])
            ->filter(fn (mixed $languageId): bool => filled($languageId))
            ->map(fn (mixed $languageId): int => (int) $languageId)
            ->values();

        $before = $this->resolvedLanguages()
            ->map(fn (Language $language): array => [
                'id' => (int) $language->id,
                'name' => (string) $language->name,
                'code' => (string) $language->code,
            ])
            ->all();

        $languageCodes = Language::query()
            ->whereIn('id', $languageIds->all())
            ->get(['id', 'code'])
            ->sortBy(fn (Language $language): int => $languageIds->search((int) $language->id) ?: 0)
            ->pluck('code')
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->values();

        OwnerContext::withOwner(null, function () use ($languageCodes): void {
            $this->languageRecords()->delete();

            foreach ($languageCodes as $index => $languageCode) {
                $this->languageRecords()->create([
                    'event_id' => (string) $this->getKey(),
                    'language_code' => $languageCode,
                    'usage_type' => 'primary',
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                    'metadata' => [
                        'source' => 'world_languages',
                    ],
                ]);
            }
        });

        $this->unsetRelation('languages');
        $this->resolvedLanguageCache = null;

        $after = $this->resolvedLanguages()
            ->map(fn (Language $language): array => [
                'id' => (int) $language->id,
                'name' => (string) $language->name,
                'code' => (string) $language->code,
            ])
            ->all();

        $this->recordCustomAuditDifferences('updated', [
            'languages' => $before,
        ], [
            'languages' => $after,
        ]);
    }

    /**
     * @return HasOne<EventInvolvement, $this>
     */
    public function primaryOrganizerInvolvement(): HasOne
    {
        return $this->hasOne(EventInvolvement::class)
            ->where('role_code', 'organizer')
            ->where('is_primary', true);
    }

    public function setPrimaryOrganizer(Institution|Person|null $organizer): static
    {
        $involvement = $this->primaryOrganizerInvolvement;

        if ($organizer === null) {
            $involvement?->delete();

            $this->unsetRelation('primaryOrganizerInvolvement');

            return $this;
        }

        if ($involvement instanceof EventInvolvement) {
            $involvement->fill([
                'event_id' => (string) $this->getKey(),
                'event_occurrence_id' => null,
                'event_session_id' => null,
                'involveable_type' => $organizer::class,
                'involveable_id' => (string) $organizer->getKey(),
                'event_role_id' => EventRole::where('code', 'organizer')->value('id'),
                'role_code' => 'organizer',
                'status' => 'confirmed',
                'visibility' => 'public',
                'prominence' => 0,
                'is_featured' => false,
                'is_primary' => true,
                'sort_order' => 0,
            ]);

            if ($involvement->isDirty()) {
                $involvement->save();
            }
        } else {
            $involvement = new EventInvolvement([
                'event_id' => (string) $this->getKey(),
                'event_occurrence_id' => null,
                'event_session_id' => null,
                'involveable_type' => $organizer::class,
                'involveable_id' => (string) $organizer->getKey(),
                'event_role_id' => EventRole::where('code', 'organizer')->value('id'),
                'role_code' => 'organizer',
                'status' => 'confirmed',
                'visibility' => 'public',
                'prominence' => 0,
                'is_featured' => false,
                'is_primary' => true,
                'sort_order' => 0,
            ]);
            $involvement->{$involvement->getKeyName()} = (string) Str::uuid();
            $involvement->save();
        }

        $this->unsetRelation('primaryOrganizerInvolvement');

        return $this;
    }

    private const array LINK_TYPE_MAP = [
        'live_url' => 'streaming',
        'event_url' => 'external',
        'recording_url' => 'recording',
    ];

    public function getLiveUrlAttribute(): ?string
    {
        if (array_key_exists('live_url', $this->pendingLinkWrites)) {
            return $this->pendingLinkWrites['live_url'];
        }

        return $this->getLinkUrl('streaming');
    }

    public function getEventUrlAttribute(): ?string
    {
        if (array_key_exists('event_url', $this->pendingLinkWrites)) {
            return $this->pendingLinkWrites['event_url'];
        }

        return $this->getLinkUrl('external');
    }

    public function getRecordingUrlAttribute(): ?string
    {
        if (array_key_exists('recording_url', $this->pendingLinkWrites)) {
            return $this->pendingLinkWrites['recording_url'];
        }

        return $this->getLinkUrl('recording');
    }

    public function setLiveUrlAttribute(?string $value): void
    {
        $this->pendingLinkWrites['live_url'] = $value;
    }

    public function setEventUrlAttribute(?string $value): void
    {
        $this->pendingLinkWrites['event_url'] = $value;
    }

    public function setRecordingUrlAttribute(?string $value): void
    {
        $this->pendingLinkWrites['recording_url'] = $value;
    }

    private function getLinkUrl(string $linkType): ?string
    {
        if ($this->relationLoaded('links')) {
            return $this->links->firstWhere('link_type', $linkType)?->url;
        }

        return $this->links()->where('link_type', $linkType)->value('url');
    }

    public function syncUrlLinks(): void
    {
        if ($this->pendingLinkWrites === []) {
            return;
        }

        foreach ($this->pendingLinkWrites as $field => $value) {
            $linkType = self::LINK_TYPE_MAP[$field];

            if ($value !== null && $value !== '') {
                EventLink::updateOrCreate(
                    ['event_id' => (string) $this->getKey(), 'link_type' => $linkType],
                    ['url' => $value, 'visibility' => 'public'],
                );
            } else {
                EventLink::query()
                    ->where('event_id', (string) $this->getKey())
                    ->where('link_type', $linkType)
                    ->delete();
            }
        }

        $this->pendingLinkWrites = [];
    }

    // ─── Prayer Time Expression (EventTimeExpression) ───────────────────────

    public function getTimingModeAttribute(mixed $value): string
    {
        return $this->prayerExpression() instanceof EventTimeExpression
            ? TimingMode::PrayerRelative->value
            : TimingMode::Absolute->value;
    }

    public function getPrayerReferenceAttribute(mixed $value): ?string
    {
        return $this->prayerExpression()?->anchor_code;
    }

    public function getPrayerOffsetAttribute(mixed $value): ?string
    {
        $expr = $this->prayerExpression();

        if ($expr?->offset_minutes === null || $expr->relation === null) {
            return null;
        }

        $signed = $expr->relation === 'before' ? -$expr->offset_minutes : $expr->offset_minutes;

        foreach (PrayerOffset::cases() as $case) {
            if ($case->minutes() === $signed) {
                return $case->value;
            }
        }

        return null;
    }

    public function getPrayerDisplayTextAttribute(?string $value): ?string
    {
        return $this->prayerExpression()?->display_label;
    }

    private function prayerExpression(): ?EventTimeExpression
    {
        if ($this->relationLoaded('timeExpressions')) {
            return $this->timeExpressions->first(fn (EventTimeExpression $e) => $e->anchor_type === 'prayer');
        }

        return $this->timeExpressions()->where('anchor_type', 'prayer')->first();
    }

    // ─── Audience (EventAudience + EventAudienceProfile) ────────────────────

    public function getGenderAttribute(mixed $value): ?string
    {
        if (array_key_exists('gender', $this->pendingAudienceWrites)) {
            return $this->pendingAudienceWrites['gender'];
        }

        if ($this->relationLoaded('audiences')) {
            return $this->audiences->firstWhere('audience_type', 'gender')?->value;
        }

        return $this->audiences()->where('audience_type', 'gender')->value('value');
    }

    /** @return list<string>|null */
    public function getAgeGroupAttribute(mixed $value): ?array
    {
        if (array_key_exists('age_group', $this->pendingAudienceWrites)) {
            return $this->pendingAudienceWrites['age_group'];
        }

        $values = $this->relationLoaded('audiences')
            ? $this->audiences->where('audience_type', 'age_group')->sortBy('sort_order')->pluck('value')->toArray()
            : $this->audiences()->where('audience_type', 'age_group')->orderBy('sort_order')->pluck('value')->toArray();

        return $values !== [] ? $values : null;
    }

    public function getChildrenAllowedAttribute(mixed $value): ?bool
    {
        if (array_key_exists('children_allowed', $this->pendingAudienceProfileWrites)) {
            return $this->pendingAudienceProfileWrites['children_allowed'] ?? null;
        }

        if ($this->relationLoaded('audienceProfiles')) {
            return $this->audienceProfiles->first()?->is_child_friendly;
        }

        return $this->audienceProfiles()->value('is_child_friendly');
    }

    public function getIsMuslimOnlyAttribute(mixed $value): ?bool
    {
        if (array_key_exists('is_muslim_only', $this->pendingAudienceWrites)) {
            return $this->pendingAudienceWrites['is_muslim_only'];
        }

        $val = $this->relationLoaded('audiences')
            ? $this->audiences->firstWhere('audience_type', 'religion')?->value
            : $this->audiences()->where('audience_type', 'religion')->value('value');

        return $val === null ? null : $val === 'muslim_only';
    }

    /**
     * @param  string|EventGenderRestriction|null  $value
     */
    public function setGenderAttribute(mixed $value): void
    {
        $this->pendingAudienceWrites['gender'] = $value instanceof EventGenderRestriction ? $value->value : $value;
    }

    /**
     * @param  array<int, string|EventAgeGroup>|string|EventAgeGroup|null  $value
     */
    public function setAgeGroupAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->pendingAudienceWrites['age_group'] = null;

            return;
        }

        $normalized = is_array($value)
            ? array_map(fn (mixed $v): string => $v instanceof EventAgeGroup ? $v->value : (string) $v, $value)
            : [($value instanceof EventAgeGroup ? $value->value : (string) $value)];

        $this->pendingAudienceWrites['age_group'] = $normalized;
    }

    public function setChildrenAllowedAttribute(mixed $value): void
    {
        $this->pendingAudienceProfileWrites['children_allowed'] = $value === null ? null : (bool) $value;
    }

    public function setIsMuslimOnlyAttribute(mixed $value): void
    {
        $this->pendingAudienceWrites['is_muslim_only'] = $value === null ? null : (bool) $value;
    }

    public function syncAudiences(): void
    {
        if ($this->pendingAudienceWrites === [] && $this->pendingAudienceProfileWrites === []) {
            return;
        }

        OwnerContext::withOwner(null, function (): void {
            foreach ($this->pendingAudienceWrites as $type => $value) {
                match ($type) {
                    'gender' => $this->syncSingleAudience('gender', $value),
                    'age_group' => $this->syncAgeGroupAudience($value),
                    'is_muslim_only' => $this->syncSingleAudience('religion', $value ? 'muslim_only' : null),
                    default => null,
                };
            }

            if ($this->pendingAudienceProfileWrites !== []) {
                EventAudienceProfile::updateOrCreate(
                    ['event_id' => $this->id],
                    ['is_child_friendly' => $this->pendingAudienceProfileWrites['children_allowed'] ?? null],
                );
            }
        });

        $this->pendingAudienceWrites = [];
        $this->pendingAudienceProfileWrites = [];
    }

    // ─── EventAttribute (is_featured) ──────────────────────────────────────

    public function getIsFeaturedAttribute(mixed $value): ?bool
    {
        if (array_key_exists('is_featured', $this->pendingAttributeWrites)) {
            return $this->pendingAttributeWrites['is_featured'];
        }

        $attr = $this->relationLoaded('attributes')
            ? $this->getRelation('attributes')->firstWhere('attribute_key', 'is_featured')
            : EventAttribute::where('event_id', $this->id)->where('attribute_key', 'is_featured')->value('attribute_value');

        return $attr === null ? null : $attr !== '0';
    }

    public function setIsFeaturedAttribute(mixed $value): void
    {
        // Single source: EventAttribute rows (synced on save).
        $this->pendingAttributeWrites['is_featured'] = $value === null ? null : (bool) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function syncAttributes(array $data = []): void
    {
        $writes = $data !== [] ? $data : $this->pendingAttributeWrites;

        if ($writes === []) {
            return;
        }

        foreach ($writes as $key => $value) {
            match ($key) {
                'is_featured' => $value === null
                    ? EventAttribute::query()
                        ->where('event_id', $this->id)
                        ->where('attribute_key', $key)
                        ->delete()
                    : EventAttribute::updateOrCreate(
                        ['event_id' => $this->id, 'attribute_key' => $key],
                        ['attribute_value' => $value ? '1' : '0'],
                    ),
                default => null,
            };
        }

        $this->pendingAttributeWrites = [];
    }

    // ─── EventLocation (primary venue space) ───────────────────────────────

    #[\Override]
    public function primaryLocation(): HasOne
    {
        return $this->hasOne(EventLocation::class)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->where('location_role', 'primary')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @param  list<string>  $spaceIds
     */
    public function syncLocation(?string $venueId = null, array $spaceIds = []): void
    {
        EventLocation::query()
            ->where('event_id', $this->id)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->delete();

        if (($venueId !== null && $venueId !== '') || $spaceIds !== []) {
            $first = true;

            foreach ($spaceIds as $i => $spaceId) {
                if (! is_string($spaceId) || $spaceId === '') {
                    continue;
                }

                EventLocation::create([
                    'event_id' => $this->id,
                    'event_occurrence_id' => null,
                    'event_session_id' => null,
                    'location_role' => $first ? 'primary' : 'additional',
                    'venue_id' => $first ? $venueId : null,
                    'venue_space_id' => $spaceId,
                    'visibility' => 'public',
                    'status' => 'active',
                    'sort_order' => $i,
                ]);

                $first = false;
            }

            if ($first && $venueId !== null && $venueId !== '') {
                EventLocation::create([
                    'event_id' => $this->id,
                    'event_occurrence_id' => null,
                    'event_session_id' => null,
                    'location_role' => 'primary',
                    'venue_id' => $venueId,
                    'venue_space_id' => null,
                    'visibility' => 'public',
                    'status' => 'active',
                    'sort_order' => 0,
                ]);
            }
        }
    }

    private function syncSingleAudience(string $type, mixed $value): void
    {
        OwnerContext::withOwner(null, function () use ($type, $value): void {
            if (! in_array($value, [null, '', false], true)) {
                EventAudience::updateOrCreate(
                    ['event_id' => $this->id, 'audience_type' => $type],
                    ['value' => (string) $value],
                );
            } else {
                EventAudience::where('event_id', $this->id)
                    ->where('audience_type', $type)
                    ->delete();
            }
        });
    }

    private function syncAgeGroupAudience(mixed $value): void
    {
        OwnerContext::withOwner(null, function () use ($value): void {
            EventAudience::where('event_id', $this->id)
                ->where('audience_type', 'age_group')
                ->delete();

            if (in_array($value, [null, [], ''], true)) {
                return;
            }

            $values = is_array($value) ? $value : [$value];

            foreach (array_values($values) as $i => $v) {
                EventAudience::create([
                    'event_id' => $this->id,
                    'audience_type' => 'age_group',
                    'value' => (string) $v,
                    'sort_order' => $i,
                ]);
            }
        });
    }

    private function primaryOccurrenceDate(string $key): mixed
    {
        if ($this->relationLoaded('primaryOccurrence')) {
            $occurrence = $this->getRelationValue('primaryOccurrence');

            return $occurrence instanceof EventOccurrence ? $occurrence->{$key} : null;
        }

        if ($this->relationLoaded('occurrences')) {
            $occurrence = $this->occurrences->first();

            return $occurrence instanceof EventOccurrence ? $occurrence->{$key} : null;
        }

        if (! $this->exists) {
            return null;
        }

        $occurrence = $this->occurrences()
            ->withoutGlobalScopes()
            ->first([$key]);

        return $occurrence instanceof EventOccurrence ? $occurrence->{$key} : null;
    }

    /**
     * @return Collection<int, Language>
     */
    private function resolvedLanguages(): Collection
    {
        if ($this->resolvedLanguageCache instanceof Collection) {
            return $this->resolvedLanguageCache;
        }

        $languageCodes = OwnerContext::withOwner(null, function (): Collection {
            $languageRecords = $this->relationLoaded('languages')
                ? $this->getRelation('languages')
                : $this->languageRecords()->get();

            $languageCodes = $languageRecords
                ->filter(fn (mixed $record): bool => $record instanceof EventLanguage)
                ->pluck('language_code')
                ->filter(fn (mixed $languageCode): bool => is_string($languageCode) && $languageCode !== '')
                ->values();

            if ($languageCodes->isNotEmpty()) {
                return $languageCodes;
            }

            return $languageRecords
                ->filter(fn (mixed $record): bool => $record instanceof Language)
                ->pluck('code')
                ->filter(fn (mixed $languageCode): bool => is_string($languageCode) && $languageCode !== '')
                ->values();
        });

        if ($languageCodes->isEmpty() && $this->exists) {
            $languageCodes = OwnerContext::withOwner(null, fn (): Collection => $this->languageRecords()
                ->pluck('language_code')
                ->filter(fn (mixed $languageCode): bool => is_string($languageCode) && $languageCode !== '')
                ->values());
        }

        if ($languageCodes->isEmpty()) {
            /** @var Collection<int, Language> $empty */
            $empty = collect();
            $this->resolvedLanguageCache = $empty;

            return $empty;
        }

        /** @var Collection<string, Language> $languagesByCode */
        $languagesByCode = Language::query()
            ->whereIn('code', $languageCodes->all())
            ->get()
            ->keyBy(fn (Language $language): string => (string) $language->code);

        /** @var Collection<int, Language> $resolved */
        $resolved = $languageCodes
            ->map(fn (string $languageCode): ?Language => $languagesByCode->get($languageCode))
            ->filter(fn (mixed $language): bool => $language instanceof Language)
            ->values();

        $this->resolvedLanguageCache = $resolved;

        return $resolved;
    }

    /**
     * Scope a query to only include active public events.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $table = $query->getModel()->getTable();

        $query->whereIn("{$table}.status", self::PUBLIC_STATUSES)
            ->where("{$table}.visibility", EventVisibility::Public)
            ->whereNotNull("{$table}.published_at");
    }

    /**
     * Scope a query to events highlighted by the product team.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function featured(Builder $query): void
    {
        $eventTable = $query->getModel()->getTable();
        $attributeTable = (new EventAttribute)->getTable();

        $query->whereExists(function (QueryBuilder $subquery) use ($attributeTable, $eventTable): void {
            $subquery
                ->selectRaw('1')
                ->from($attributeTable)
                ->whereColumn("{$attributeTable}.event_id", "{$eventTable}.id")
                ->where("{$attributeTable}.attribute_key", 'is_featured')
                ->where("{$attributeTable}.attribute_value", '1');
        });
    }

    /**
     * Scope a query to public event containers. Scheduling lives in occurrences
     * and sessions, so every event remains discoverable at the event level.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function discoverable(Builder $query): void
    {
        // Kept as a named scope for callers; there is no legacy event hierarchy.
    }

    public function bookReference(): ?Reference
    {
        if ($this->relationLoaded('references')) {
            /** @var ?Reference $reference */
            $reference = $this->references->first(function (Reference $reference): bool {
                $referenceType = $reference->type;

                if ($referenceType instanceof ReferenceType) {
                    return $referenceType === ReferenceType::Book;
                }

                return (string) $referenceType === ReferenceType::Book->value;
            });

            return $reference;
        }

        /** @var ?Reference $reference */
        $reference = $this->references()
            ->where('references.type', ReferenceType::Book->value)
            ->first();

        return $reference;
    }

    public function hasBookReference(): bool
    {
        return $this->bookReference() instanceof Reference;
    }

    public function getReferenceStudySubtitleAttribute(): ?string
    {
        return $this->bookReference()?->title;
    }

    /**
     * Determine if the model should be searchable.
     * Index active public events (including cancelled notices).
     */
    public function shouldBeSearchable(): bool
    {
        return $this->published_at !== null
            && in_array((string) $this->status, self::PUBLIC_STATUSES, true)
            && $this->visibility === EventVisibility::Public;
    }

    public function isPubliclyReachable(): bool
    {
        $visibility = $this->visibility;
        $visibleByLink = $visibility instanceof EventVisibility
            ? in_array($visibility, [EventVisibility::Public, EventVisibility::Unlisted], true)
            : in_array((string) $visibility, [EventVisibility::Public->value, EventVisibility::Unlisted->value], true);

        return $this->published_at !== null
            && $visibleByLink
            && in_array((string) $this->status, self::PUBLIC_STATUSES, true);
    }

    public function replacementLinkTarget(): ?self
    {
        return $this->resolveReachableReplacementEvent(
            $this->latestPublishedReplacementAnnouncement?->replacementEvent,
        );
    }

    public function replacementLinkTargetForAnnouncement(?EventChangeAnnouncement $announcement): ?self
    {
        return $this->resolveReachableReplacementEvent($announcement?->replacementEvent);
    }

    private function resolveReachableReplacementEvent(?Model $event): ?self
    {
        if (! $event instanceof self) {
            return null;
        }

        /** @var array<string, true> $visited */
        $visited = [(string) $this->getKey() => true];
        $current = $event;
        $latestReachable = null;

        while (! isset($visited[(string) $current->getKey()])) {
            $visited[(string) $current->getKey()] = true;

            if ($current->isPubliclyReachable()) {
                $latestReachable = $current;
            }

            $current->loadMissing('latestPublishedReplacementAnnouncement.replacementEvent');

            $nextAnnouncement = $current->latestPublishedReplacementAnnouncement;
            $nextReplacement = $nextAnnouncement?->replacementEvent;

            if (! $nextAnnouncement instanceof EventChangeAnnouncement || ! $nextReplacement instanceof self) {
                break;
            }

            $current = $nextReplacement;
        }

        return $latestReachable;
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged([
            'title',
            'description',
            'slug',
            'language',
            'gender',
            'age_group',
            'children_allowed',
            'status',
            'visibility',
            'institution_id',
            'published_at',
        ]);
    }

    public function getPublicChangeBadgeLabelAttribute(): ?string
    {
        if ((string) $this->status === 'cancelled') {
            return EventChangeType::Cancelled->publicBadgeLabel();
        }

        if ($this->primaryOccurrence && $this->primaryOccurrence->status === 'postponed') {
            return EventChangeType::Postponed->publicBadgeLabel();
        }

        $notice = $this->latestPublishedChangeAnnouncement;

        if (! $notice instanceof EventChangeAnnouncement) {
            return null;
        }

        $updateType = $notice->update_type;

        return $updateType instanceof EventChangeType ? $updateType->publicBadgeLabel() : null;
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query
            ->with(['institution', 'institution.addresses', 'venue', 'venue.addresses', 'persons', 'keyPeople.person', 'references', 'classifications', 'primaryOccurrence', 'timeExpressions'])
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', self::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public);
    }

    /**
     * Get the indexable data array for the model.
     * Schema matches documentation B8.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        if ($this->usesScoutDatabaseDriver()) {
            return $this->toScoutDatabaseSearchableArray();
        }

        $this->loadMissing(['institution', 'institution.addresses', 'venue', 'venue.addresses', 'persons', 'keyPeople.person', 'references', 'classifications', 'primaryOccurrence', 'timeExpressions']);
        $venueAddress = $this->venue?->primaryAddress();
        $institutionAddress = $this->institution?->primaryAddress();
        $institution = $this->institution;
        $venue = $this->venue;
        $gender = $this->gender;
        $eventFormat = $this->delivery_mode;
        $visibility = $this->visibility;
        $primaryOccurrence = $this->primaryOccurrence;
        $prayerExpression = $this->timeExpressions->first(fn (EventTimeExpression $expression): bool => $expression->anchor_type === 'prayer');
        $occurrenceStatus = $primaryOccurrence?->status;
        $timingMode = $prayerExpression instanceof EventTimeExpression
            ? TimingMode::PrayerRelative->value
            : TimingMode::Absolute->value;

        $languageCodes = $this->resolvedLanguages()
            ->pluck('code')
            ->filter(fn (mixed $languageCode): bool => is_string($languageCode) && $languageCode !== '')
            ->unique()
            ->values()
            ->all();

        if ($languageCodes === [] && is_string($this->language) && $this->language !== '') {
            $languageCodes = [$this->language];
        }

        $ageGroupCollection = $this->age_group;

        $ageGroupValues = $ageGroupCollection instanceof Collection && $ageGroupCollection->isNotEmpty()
            ? $ageGroupCollection->map(fn (EventAgeGroup $value): string => $value->value)->toArray()
            : ['all_ages'];

        /** @var \Illuminate\Database\Eloquent\Collection<int, EventClassification> $classifications */
        $classifications = $this->relationLoaded('classifications')
            ? $this->classifications
            : $this->classifications()->get();

        $topicIds = $classifications
            ->filter(fn (EventClassification $classification): bool => in_array($classification->taxonomy_code, [
                EventTaxonomyCode::Discipline->value,
                EventTaxonomyCode::Issue->value,
            ], true))
            ->pluck('event_term_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $domainTagIds = $classifications
            ->filter(fn (EventClassification $classification): bool => $classification->taxonomy_code === EventTaxonomyCode::Domain->value)
            ->pluck('event_term_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $sourceTagIds = $classifications
            ->filter(fn (EventClassification $classification): bool => $classification->taxonomy_code === EventTaxonomyCode::Source->value)
            ->pluck('event_term_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        /** @var \Illuminate\Database\Eloquent\Collection<int, EventKeyPerson> $keyPeople */
        $keyPeople = $this->keyPeople;

        /** @var list<string> $keyPersonRoles */
        $keyPersonRoles = [];

        foreach ($keyPeople as $keyPerson) {
            $role = EventKeyPersonRole::tryFrom((string) $keyPerson->role_code);

            if ($role instanceof EventKeyPersonRole && ! in_array($role->value, $keyPersonRoles, true)) {
                $keyPersonRoles[] = $role->value;
            }
        }

        $keyPersonPersonIds = $keyPeople
            ->pluck('involveable_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->unique()
            ->values()
            ->all();

        $personInChargeIds = $keyPeople
            ->where('role_code', EventKeyPersonRole::PersonInCharge->value)
            ->pluck('involveable_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $personInChargeNames = $keyPeople
            ->where('role_code', EventKeyPersonRole::PersonInCharge->value)
            ->map(function (EventKeyPerson $keyPerson): string {
                if ($keyPerson->person instanceof Person) {
                    $searchableName = trim((string) $keyPerson->person->searchable_name);

                    return $searchableName !== '' ? $searchableName : (string) $keyPerson->person->name;
                }

                return (string) ($keyPerson->display_name ?? '');
            })
            ->filter(fn (string $name): bool => trim($name) !== '')
            ->unique()
            ->values()
            ->implode(', ');

        $moderatorIds = $keyPeople
            ->where('role_code', EventKeyPersonRole::Moderator->value)
            ->pluck('involveable_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $imamIds = $keyPeople
            ->where('role_code', EventKeyPersonRole::Imam->value)
            ->pluck('involveable_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $khatibIds = $keyPeople
            ->where('role_code', EventKeyPersonRole::Khatib->value)
            ->pluck('involveable_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $bilalIds = $keyPeople
            ->where('role_code', EventKeyPersonRole::Bilal->value)
            ->pluck('involveable_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $issueTagIds = $classifications
            ->filter(fn (EventClassification $classification): bool => $classification->taxonomy_code === EventTaxonomyCode::Issue->value)
            ->pluck('event_term_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $taxonomyTermIds = $classifications
            ->pluck('event_term_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $taxonomyCodes = $classifications
            ->pluck('taxonomy_code')
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->unique()
            ->values()
            ->all();

        $array = [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description_text,
            'slug' => $this->slug,
            'person_names' => $this->personKeyPeople
                ->map(fn (EventKeyPerson $keyPerson): string => $keyPerson->person !== null ? $keyPerson->person->name : (string) ($keyPerson->display_name ?? ''))
                ->filter(fn (string $name): bool => $name !== '')
                ->implode(', '),
            'institution_id' => $this->institution_id,
            'institution_name' => $institution instanceof Institution ? $institution->name : '',
            'venue_id' => $this->default_venue_id,
            'venue_name' => $venue instanceof Venue ? $venue->name : '',
            'country_code' => $venueAddress->country_code ?? $institutionAddress?->country_code,
            'country_id' => $venueAddress->country_id ?? $institutionAddress?->country_id,
            'state_id' => $venueAddress->state_id ?? $institutionAddress?->state_id,
            'city_id' => $venueAddress->city_id ?? $institutionAddress?->city_id,
            'admin_area_1_id' => $venueAddress->admin_area_1_id ?? $institutionAddress?->admin_area_1_id,
            'admin_area_2_id' => $venueAddress->admin_area_2_id ?? $institutionAddress?->admin_area_2_id,
            'city' => $venueAddress->city ?? $institutionAddress?->city,
            'state' => $venueAddress->state ?? $institutionAddress?->state,
            'postcode' => $venueAddress->postcode ?? $institutionAddress?->postcode,
            'language_codes' => $languageCodes,
            'gender' => $gender instanceof EventGenderRestriction ? $gender->value : ((is_string($gender) && $gender !== '') ? $gender : 'all'),
            'age_group' => $ageGroupValues,
            'event_format' => $eventFormat instanceof EventFormat ? $eventFormat->value : ((is_string($eventFormat) && $eventFormat !== '') ? $eventFormat : 'physical'),
            'children_allowed' => $this->children_allowed ?? true,
            'status' => (string) $this->status,
            'visibility' => $visibility instanceof EventVisibility ? $visibility->value : ((is_string($visibility) && $visibility !== '') ? $visibility : 'public'),
            'occurrence_status' => $occurrenceStatus instanceof BackedEnum ? $occurrenceStatus->value : ((is_string($occurrenceStatus) && $occurrenceStatus !== '') ? $occurrenceStatus : null),
            'timing_mode' => $timingMode,
            'topic_ids' => $topicIds,
            'domain_tag_ids' => $domainTagIds,
            'source_tag_ids' => $sourceTagIds,
            'issue_tag_ids' => $issueTagIds,
            'taxonomy_term_ids' => $taxonomyTermIds,
            'taxonomy_codes' => $taxonomyCodes,
            'reference_ids' => $this->references->pluck('id')->values()->all(),
            'person_ids' => $this->personKeyPeople
                ->pluck('involveable_id')
                ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
                ->values()
                ->all(),
            'key_person_roles' => $keyPersonRoles,
            'key_person_person_ids' => $keyPersonPersonIds,
            'person_in_charge_ids' => $personInChargeIds,
            'person_in_charge_names' => $personInChargeNames,
            'moderator_ids' => $moderatorIds,
            'imam_ids' => $imamIds,
            'khatib_ids' => $khatibIds,
            'bilal_ids' => $bilalIds,
            'starts_at' => $this->starts_at instanceof Carbon ? $this->starts_at->timestamp : 0,
            'ends_at' => $this->ends_at instanceof Carbon ? $this->ends_at->timestamp : null,
            'saves_count' => $this->saves_count ?? 0,
            'registrations_count' => $this->registrations_count ?? 0,
        ];

        if ($venueAddress instanceof Address && $venueAddress->latitude !== null && $venueAddress->longitude !== null) {
            $array['location'] = [(float) $venueAddress->latitude, (float) $venueAddress->longitude];
        } elseif ($institutionAddress instanceof Address && $institutionAddress->latitude !== null && $institutionAddress->longitude !== null) {
            $array['location'] = [(float) $institutionAddress->latitude, (float) $institutionAddress->longitude];
        }

        return $array;
    }

    /**
     * @return array<string, string>
     */
    private function toScoutDatabaseSearchableArray(): array
    {
        $description = trim(strip_tags((string) $this->description));

        return array_filter([
            'title' => (string) $this->title,
            'description' => $description !== '' ? $description : null,
            'slug' => (string) $this->slug,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function usesScoutDatabaseDriver(): bool
    {
        return (string) config('scout.driver') === 'database';
    }

    /**
     * @return HasMany<MemberInvitation, $this>
     */
    public function memberInvitations(): HasMany
    {
        return $this->hasMany(MemberInvitation::class, 'subject_id')
            ->where('subject_type', MemberSubjectType::Event->value);
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'default_venue_id');
    }

    /**
     * @return BelongsToMany<Series, $this, EventSeriesItemPivot, 'pivot'>
     */
    public function series(): BelongsToMany
    {
        return $this->belongsToMany(
            Series::class,
            config('events.database.tables.event_series_items', 'event_series_items'),
            'event_id',
            'event_series_id',
        )
            ->using(EventSeriesItemPivot::class)
            ->withPivot('id', 'seriesable_type', 'seriesable_id', 'sort_order')
            ->wherePivot('seriesable_type', self::class)
            ->withPivotValue('seriesable_type', self::class)
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /** @return HasMany<EventClassification, $this> */
    public function categoryClassifications(): HasMany
    {
        return $this->classifications()
            ->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE)
            ->orderBy('sort_order');
    }

    /**
     * @return HasOne<EventAccessPolicy, $this>
     */
    public function accessPolicy(): HasOne
    {
        return $this->hasOne(EventAccessPolicy::class)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id');
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function keyPeople(): HasMany
    {
        return $this->hasMany(EventKeyPerson::class)
            ->where('role_code', '!=', 'organizer')
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function personKeyPeople(): HasMany
    {
        return $this->keyPeople()->where('role_code', EventKeyPersonRole::Speaker->value);
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function nonSpeakerKeyPeople(): HasMany
    {
        return $this->keyPeople()->where('role_code', '!=', EventKeyPersonRole::Speaker->value);
    }

    public function resolvedRegistrationMode(): PackageRegistrationMode
    {
        $mode = parent::getAttribute('registration_mode');

        if ($mode instanceof PackageRegistrationMode) {
            return $mode;
        }

        if (is_string($mode) && $mode !== '') {
            $resolvedMode = PackageRegistrationMode::tryFrom($mode);

            if ($resolvedMode instanceof PackageRegistrationMode) {
                return $resolvedMode;
            }
        }

        return $this->accessPolicy?->registration_required
            ? PackageRegistrationMode::Required
            : PackageRegistrationMode::None;
    }

    /**
     * @return BelongsToMany<Person, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'event_involvements', 'event_id', 'involveable_id')
            ->using(EventKeyPersonPivot::class)
            ->wherePivot('involveable_type', 'person')
            ->wherePivot('role_code', EventKeyPersonRole::Speaker->value)
            ->withPivotValue('involveable_type', 'person')
            ->withPivotValue('role_code', EventKeyPersonRole::Speaker->value)
            ->withPivot(['id', 'involveable_type', 'role_code', 'sort_order', 'notes'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return BelongsToMany<Person, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function speakers(): BelongsToMany
    {
        return $this->persons();
    }

    /**
     * Catalog references attached via package event_references pivot.
     *
     * @return BelongsToMany<Reference, $this, EventReferencePivot, 'pivot'>
     */
    public function references(): BelongsToMany
    {
        return $this->belongsToMany(
            Reference::class,
            config('events.database.tables.event_references', 'event_references'),
            'event_id',
            'referenceable_id',
        )
            ->using(EventReferencePivot::class)
            ->wherePivot('referenceable_type', 'reference')
            ->withPivotValue('referenceable_type', 'reference')
            ->withPivotValue('visibility', 'public')
            ->withPivotValue('reference_type', 'book')
            ->withPivot(['id', 'sort_order', 'visibility', 'reference_type', 'title'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Raw package EventReference rows (includes non-catalog links).
     *
     * @return HasMany<EventReference, $this>
     */
    public function eventReferences(): HasMany
    {
        return $this->hasMany(EventReference::class)->orderBy('sort_order');
    }

    /**
     * @return MorphMany<MediaLink, $this>
     */
    public function mediaLinks(): MorphMany
    {
        return $this->morphMany(MediaLink::class, 'mediable');
    }

    /**
     * @return HasMany<EventSubmission, $this>
     */
    #[\Override]
    public function submissions(): HasMany
    {
        return $this->hasMany(EventSubmission::class);
    }

    /**
     * @return HasMany<ModerationReview, $this>
     */
    public function moderationReviews(): HasMany
    {
        return $this->hasMany(ModerationReview::class, 'actionable_id')
            ->where('actionable_type', Event::class);
    }

    /**
     * @return HasOne<ModerationReview, $this>
     */
    public function latestModerationReview(): HasOne
    {
        return $this->hasOne(ModerationReview::class, 'actionable_id')
            ->where('actionable_type', Event::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return HasMany<EventChangeAnnouncement, $this>
     */
    public function changeAnnouncements(): HasMany
    {
        return $this->hasMany(EventChangeAnnouncement::class)
            ->whereIn('update_type', array_map(
                static fn (EventChangeType $type): string => $type->value,
                EventChangeType::cases(),
            ));
    }

    /**
     * @return HasMany<EventChangeAnnouncement, $this>
     */
    public function publishedChangeAnnouncements(): HasMany
    {
        return $this->changeAnnouncements()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return HasOne<EventChangeAnnouncement, $this>
     */
    public function latestPublishedChangeAnnouncement(): HasOne
    {
        return $this->latestPublishedAnnouncementRelation();
    }

    /**
     * @return HasOne<EventChangeAnnouncement, $this>
     */
    public function latestPublishedReplacementAnnouncement(): HasOne
    {
        return $this->latestPublishedAnnouncementRelation(function (Builder|QueryBuilder $query, string $table): void {
            $query->whereNotNull("{$table}.replacement_event_id");
        });
    }

    /**
     * @return HasMany<EventChangeAnnouncement, $this>
     */
    public function incomingReplacementAnnouncements(): HasMany
    {
        return $this->hasMany(EventChangeAnnouncement::class, 'replacement_event_id')
            ->whereIn('update_type', array_map(
                static fn (EventChangeType $type): string => $type->value,
                EventChangeType::cases(),
            ));
    }

    /**
     * @return HasOne<EventChangeAnnouncement, $this>
     */
    public function latestIncomingReplacementAnnouncement(): HasOne
    {
        return $this->latestPublishedAnnouncementRelation(
            relation: $this->hasOne(EventChangeAnnouncement::class, 'replacement_event_id'),
        );
    }

    /**
     * UUID primary keys cannot be aggregated portably with `MAX()` on PostgreSQL,
     * so resolve the latest row by excluding any newer published candidate.
     *
     * @param  HasOne<EventChangeAnnouncement, $this>|null  $relation
     * @return HasOne<EventChangeAnnouncement, $this>
     */
    private function latestPublishedAnnouncementRelation(
        ?\Closure $extraConstraint = null,
        ?HasOne $relation = null,
    ): HasOne {
        $baseRelation = $relation ?? $this->hasOne(EventChangeAnnouncement::class);
        $relatedTable = $baseRelation->getRelated()->getTable();
        $foreignKey = $baseRelation->getForeignKeyName();
        $candidateAlias = 'event_change_announcements_candidate';

        $this->applyLatestPublishedAnnouncementFilters(
            $baseRelation->getQuery(),
            $relatedTable,
            $extraConstraint,
        );

        return $baseRelation
            ->whereNotExists(function (QueryBuilder $query) use (
                $candidateAlias,
                $extraConstraint,
                $foreignKey,
                $relatedTable,
            ): void {
                $query
                    ->selectRaw('1')
                    ->from("{$relatedTable} as {$candidateAlias}")
                    ->whereColumn("{$candidateAlias}.{$foreignKey}", "{$relatedTable}.{$foreignKey}");

                $this->applyLatestPublishedAnnouncementFilters($query, $candidateAlias, $extraConstraint);
                $this->applyLatestPublishedAnnouncementTieBreaker($query, $candidateAlias, $relatedTable);
            })
            ->orderByDesc("{$relatedTable}.published_at")
            ->orderByDesc("{$relatedTable}.created_at")
            ->orderByDesc("{$relatedTable}.id");
    }

    /**
     * @param  Builder<EventChangeAnnouncement>|QueryBuilder  $query
     * @param  (\Closure(Builder<EventChangeAnnouncement>|QueryBuilder, string): void)|null  $extraConstraint
     */
    private function applyLatestPublishedAnnouncementFilters(
        Builder|QueryBuilder $query,
        string $table,
        ?\Closure $extraConstraint = null,
    ): void {
        $query
            ->whereNotNull("{$table}.published_at")
            ->whereNull("{$table}.archived_at")
            ->whereIn("{$table}.update_type", array_map(
                static fn (EventChangeType $type): string => $type->value,
                EventChangeType::cases(),
            ));

        $extraConstraint?->__invoke($query, $table);
    }

    /**
     * @param  Builder<EventChangeAnnouncement>|QueryBuilder  $query
     */
    private function applyLatestPublishedAnnouncementTieBreaker(
        Builder|QueryBuilder $query,
        string $candidateTable,
        string $currentTable,
    ): void {
        $query->where(function (QueryBuilder $comparisonQuery) use ($candidateTable, $currentTable): void {
            $comparisonQuery
                ->whereColumn("{$candidateTable}.published_at", '>', "{$currentTable}.published_at")
                ->orWhere(function (QueryBuilder $createdAtQuery) use ($candidateTable, $currentTable): void {
                    $createdAtQuery
                        ->whereColumn("{$candidateTable}.published_at", "{$currentTable}.published_at")
                        ->whereColumn("{$candidateTable}.created_at", '>', "{$currentTable}.created_at");
                })
                ->orWhere(function (QueryBuilder $idQuery) use ($candidateTable, $currentTable): void {
                    $idQuery
                        ->whereColumn("{$candidateTable}.published_at", "{$currentTable}.published_at")
                        ->whereColumn("{$candidateTable}.created_at", "{$currentTable}.created_at")
                        ->whereColumn("{$candidateTable}.id", '>', "{$currentTable}.id");
                });
        });
    }

    /**
     * @return HasMany<EventCheckin, $this>
     */
    public function checkins(): HasMany
    {
        return $this->hasMany(EventCheckin::class);
    }

    /**
     * @return MorphMany<Bookmark, $this>
     */
    public function savedBy(): MorphMany
    {
        return $this->morphMany(Bookmark::class, 'bookmarkable')->active();
    }

    /**
     * @return MorphMany<Response, $this>
     */
    public function goingBy(): MorphMany
    {
        return $this->responses()->where('response_type', 'going');
    }

    /**
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'entity');
    }

    /**
     * @return MorphOne<DonationChannel, $this>
     */
    public function donationChannel(): MorphOne
    {
        return $this->morphOne(DonationChannel::class, 'donatable')
            ->where('is_default', true);
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
            ->useFallbackUrl(asset('images/placeholders/event.png'))
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('poster')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/event.png'))
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('gallery')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->withResponsiveImages();
    }

    #[\Override]
    protected static function registrationModelClass(): string
    {
        return Registration::class;
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

        $this->addMediaConversion('card')
            ->performOnCollections('cover', 'poster')
            ->fit(Fit::Max, 1920, 1080)
            ->format('webp');

        $this->addMediaConversion('preview')
            ->performOnCollections('cover', 'poster')
            ->fit(Fit::Max, 1920, 1080)
            ->format('webp');
    }

    /**
     * Check if this event uses prayer-relative timing.
     */
    public function isPrayerRelative(): bool
    {
        if ($this->relationLoaded('timeExpressions')) {
            return $this->timeExpressions->contains(
                fn (EventTimeExpression $expression): bool => $expression->time_mode === 'prayer_relative',
            );
        }

        return $this->timeExpressions()
            ->where('time_mode', 'prayer_relative')
            ->exists();
    }

    /**
     * Get the human-readable timing display text.
     * Returns prayer-relative text (e.g., "Selepas Maghrib") or formatted time.
     */
    public function getTimingDisplayAttribute(): string
    {
        if ($this->isPrayerRelative()) {
            $prayerReference = $this->prayer_reference instanceof PrayerReference
                ? $this->prayer_reference
                : PrayerReference::tryFrom((string) $this->prayer_reference);
            $prayerOffset = $this->prayer_offset instanceof PrayerOffset
                ? $this->prayer_offset
                : PrayerOffset::tryFrom((string) $this->prayer_offset);
            $prayerTime = EventPrayerTime::fromPrayerTiming($prayerReference, $prayerOffset);

            if ($prayerTime instanceof EventPrayerTime) {
                return $prayerTime->getLabel();
            }

            if ($this->prayer_display_text) {
                return $this->prayer_display_text;
            }
        }

        // Fallback to timezone-aware formatted time (viewer timezone)
        return UserDateTimeFormatter::format($this->starts_at, 'g:i A');
    }

    /**
     * Get the full timing display with date context.
     */
    public function getFullTimingDisplayAttribute(): string
    {
        $date = $this->starts_at?->translatedFormat('l, j F Y') ?? '';
        $time = $this->timing_display;

        return "{$date} - {$time}";
    }

    /**
     * Get coordinates for prayer time calculation.
     * Falls back to venue coordinates if specific coords not set.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function getPrayerCoordinatesAttribute(): ?array
    {
        $venueAddress = $this->venue?->primaryAddress();

        if ($venueAddress instanceof Address && $venueAddress->latitude !== null && $venueAddress->longitude !== null) {
            return [
                'lat' => (float) $venueAddress->latitude,
                'lng' => (float) $venueAddress->longitude,
            ];
        }

        return null;
    }

    /**
     * @return array{width: int, height: int}
     */
    private function resolvePosterDimensions(): array
    {
        if (is_array($this->resolvedPosterDimensions)) {
            return $this->resolvedPosterDimensions;
        }

        $posterMedia = $this->getFirstMedia('poster');

        if (! $posterMedia instanceof Media) {
            return $this->resolvedPosterDimensions = ['width' => 0, 'height' => 0];
        }

        $storedDimensions = $posterMedia->getCustomProperty('source_dimensions', []);
        $storedWidth = is_array($storedDimensions) ? (int) ($storedDimensions['width'] ?? 0) : 0;
        $storedHeight = is_array($storedDimensions) ? (int) ($storedDimensions['height'] ?? 0) : 0;
        $width = (int) ($posterMedia->width ?? 0);
        $height = (int) ($posterMedia->height ?? 0);

        if (($width <= 0 || $height <= 0) && $storedWidth > 0 && $storedHeight > 0) {
            $width = $storedWidth;
            $height = $storedHeight;
        }

        $posterPath = $posterMedia->getPath();
        $relativePosterPath = $posterMedia->getPathRelativeToRoot();

        if ($posterPath !== '' && ! is_file($posterPath)) {
            try {
                $posterPath = Storage::disk($posterMedia->disk)->path($relativePosterPath);
            } catch (\Throwable) {
                $posterPath = '';
            }
        }

        if (($width <= 0 || $height <= 0) && $posterPath !== '' && is_file($posterPath)) {
            $dimensions = @getimagesize($posterPath);

            if (is_array($dimensions)) {
                $width = $dimensions[0];
                $height = $dimensions[1];
            }
        }

        if (($width <= 0 || $height <= 0) && $relativePosterPath !== '') {
            try {
                $posterContents = Storage::disk($posterMedia->disk)->get($relativePosterPath);
                $dimensions = @getimagesizefromstring($posterContents);

                if (is_array($dimensions)) {
                    $width = $dimensions[0];
                    $height = $dimensions[1];
                }
            } catch (\Throwable) {
            }
        }

        $this->storePosterDimensions($posterMedia, $width, $height);

        return $this->resolvedPosterDimensions = ['width' => $width, 'height' => $height];
    }

    private function storePosterDimensions(Media $posterMedia, int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $storedDimensions = $posterMedia->getCustomProperty('source_dimensions', []);
        $storedWidth = is_array($storedDimensions) ? (int) ($storedDimensions['width'] ?? 0) : 0;
        $storedHeight = is_array($storedDimensions) ? (int) ($storedDimensions['height'] ?? 0) : 0;

        if ($storedWidth === $width && $storedHeight === $height) {
            return;
        }

        $posterMedia->setCustomProperty('source_dimensions', [
            'width' => $width,
            'height' => $height,
        ]);
        $posterMedia->saveQuietly();
    }

    /**
     * Get the card image URL for frontend.
     * Priority: Cover card -> Poster card -> Institution logo thumb -> Default.
     */
    public function getRecommendationImageUrlAttribute(): string
    {
        $coverUrl = $this->preferredMediaUrl($this->getFirstMedia('cover'), ['card', 'preview', 'thumb']);

        return $coverUrl ?? asset('images/placeholders/event.png');
    }

    /**
     * Get the card image URL for frontend.
     * Priority: Cover card -> Poster card -> Institution logo thumb -> Default.
     */
    public function getCardImageUrlAttribute(): string
    {
        $coverUrl = $this->preferredMediaUrl($this->getFirstMedia('cover'), ['card', 'preview', 'thumb']);

        if ($coverUrl !== null) {
            return $coverUrl;
        }

        $posterUrl = $this->preferredMediaUrl($this->getFirstMedia('poster'), ['card', 'preview', 'thumb']);

        if ($posterUrl !== null) {
            return $posterUrl;
        }

        if ($this->institution?->hasMedia('logo')) {
            $institutionLogoUrl = $this->preferredMediaUrl($this->institution->getFirstMedia('logo'), ['thumb']);

            if ($institutionLogoUrl !== null) {
                return $institutionLogoUrl;
            }
        }

        return asset('images/placeholders/event.png');
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

    public function getPosterDisplayAspectRatioAttribute(): string
    {
        ['width' => $width, 'height' => $height] = $this->resolvePosterDimensions();

        if ($width <= 0 || $height <= 0) {
            return '16:9';
        }

        $ratio = $width / $height;
        $supportedRatios = [
            '4:5' => 4 / 5,
            '16:9' => 16 / 9,
        ];

        $closestRatio = '16:9';
        $closestDelta = INF;

        foreach ($supportedRatios as $supportedRatioKey => $supportedRatioValue) {
            $delta = abs($ratio - $supportedRatioValue);

            if ($delta < $closestDelta) {
                $closestRatio = $supportedRatioKey;
                $closestDelta = $delta;
            }
        }

        return $closestRatio;
    }

    public function getPosterOrientationAttribute(): string
    {
        ['width' => $width, 'height' => $height] = $this->resolvePosterDimensions();

        if ($width <= 0 || $height <= 0) {
            return 'landscape';
        }

        if ($height > $width) {
            return 'portrait';
        }

        if ($height === $width) {
            return 'square';
        }

        return 'landscape';
    }

    /**
     * Map 'language' to primary language from relationship.
     */
    public function getLanguageAttribute(): string
    {
        // Check if there's a 'language' column first (to avoid recursion if we add it later)
        if (array_key_exists('language', $this->attributes)) {
            return $this->attributes['language'];
        }

        $language = $this->resolvedLanguages()->first();

        if ($language instanceof Language && is_string($language->code) && $language->code !== '') {
            return $language->code;
        }

        return 'ms';
    }

    public function getDescriptionTextAttribute(): string
    {
        return $this->normalizeDescriptionText($this->description);
    }

    private function normalizeDescriptionText(mixed $description): string
    {
        if (is_string($description)) {
            return trim(strip_tags($description));
        }

        if (! is_array($description)) {
            return '';
        }

        $html = data_get($description, 'html');

        if (is_string($html) && $html !== '') {
            return trim(strip_tags($html));
        }

        $content = data_get($description, 'content');

        if (is_string($content) && $content !== '') {
            return trim($content);
        }

        return trim(collect($description)
            ->flatten()
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->implode(' '));
    }

    /**
     * Check if a user can manage this event.
     * Uses Authz scoped roles via event membership or organizer/institution scope.
     */
    public function userCanManage(User $user): bool
    {
        return $this->userHasScopedEventPermission($user, 'event.update');
    }

    /**
     * Check if a user can delete this event.
     * More restrictive than manage: requires event.delete permission in scope.
     */
    public function userCanDelete(User $user): bool
    {
        return $this->userHasScopedEventPermission($user, 'event.delete');
    }

    /**
     * Check if a user can view this event (private events).
     */
    public function userCanView(User $user): bool
    {
        return $this->userHasScopedEventPermission($user, 'event.view');
    }

    /**
     * Check if a user can approve a pending public submission tied to their responsible scope.
     */
    public function userCanApprovePublicSubmission(User $user): bool
    {
        if (! $this->status instanceof Pending) {
            return false;
        }

        if (! $this->submissions()->exists()) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'moderator'])) {
            return true;
        }

        return $this->userHasScopedEventPermission($user, 'event.approve', includeEventScope: false);
    }

    public function userHasScopedEventPermission(User $user, string $permission, bool $includeEventScope = true): bool
    {
        $memberPermissions = app(MemberPermissionGate::class);

        if ($includeEventScope && $memberPermissions->canEvent($user, $permission, $this)) {
            return true;
        }

        if ($this->organizer instanceof Institution && $memberPermissions->canInstitution($user, $permission, $this->organizer)) {
            return true;
        }

        if ($this->organizer instanceof Person && $memberPermissions->canPerson($user, $permission, $this->organizer)) {
            return true;
        }

        return $this->institution instanceof Institution && $memberPermissions->canInstitution($user, $permission, $this->institution);
    }
}
