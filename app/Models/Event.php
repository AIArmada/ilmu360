<?php

namespace App\Models;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Events\Enums\RegistrationMode as PackageRegistrationMode;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventAttribute;
use AIArmada\Events\Models\EventAudience;
use AIArmada\Events\Models\EventAudienceProfile;
use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventLanguage;
use AIArmada\Events\Models\EventLink;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use App\Enums\EventAgeGroup;
use App\Enums\EventChangeStatus;
use App\Enums\EventChangeType;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventStructure;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\MemberSubjectType;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\ReferenceType;
use App\Enums\ScheduleState;
use App\Enums\TagType;
use App\Enums\TimingMode;
use App\Models\Builders\EventBuilder;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasDonationChannels;
use App\Models\Concerns\HasPrimaryAddressAccessors;
use App\Services\PrayerTimeExpressionResolver;
use App\States\EventStatus\EventStatus;
use App\States\EventStatus\Pending;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Timezone\UserDateTimeFormatter;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
use Spatie\Tags\HasTags;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string|null $institution_id
 * @property string|null $submitter_id
 * @property string|null $venue_id
 * @property string|null $space_id
 * @property string|null $parent_event_id
 * @property string $title
 * @property string $slug
 * @property array<string, mixed>|string|null $description
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $timezone
 * @property EventStatus|string $status
 * @property string|null $schedule_kind
 * @property ScheduleState|string|null $schedule_state
 * @property EventVisibility|string|null $visibility
 * @property EventFormat|string|null $event_format
 * @property EventStructure|string $event_structure
 * @property TimingMode|string|null $timing_mode
 * @property PrayerReference|string|null $prayer_reference
 * @property PrayerOffset|string|null $prayer_offset
 * @property string|null $prayer_display_text
 * @property EventGenderRestriction|string|null $gender
 * @property Collection<int, EventAgeGroup>|array<int, string>|null $age_group
 * @property Collection<int, EventType>|array<int, string>|null $event_type
 * @property bool|null $children_allowed
 * @property string|null $live_url
 * @property string|null $event_url
 * @property string|null $recording_url
 * @property int|null $views_count
 * @property int|null $saves_count
 * @property int|null $registrations_count
 * @property int|null $going_count
 * @property Carbon|null $published_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $escalated_at
 * @property bool|null $is_priority
 * @property bool|null $is_featured
 * @property bool $is_active
 * @property bool|null $is_muslim_only
 * @property-read Institution|null $institution
 * @property-read Institution|Speaker|null $organizer
 * @property-read Venue|null $venue
 * @property-read EventChangeAnnouncement|null $latestPublishedChangeAnnouncement
 * @property-read EventChangeAnnouncement|null $latestPublishedReplacementAnnouncement
 * @property-read string|null $reference_study_subtitle
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventKeyPerson> $keyPeople
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventInvolvement> $involvements
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventOccurrence> $occurrences
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Reference> $references
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Speaker> $speakers
 * @property string|null $delivery_mode
 * @property Carbon|null $updated_at
 * @property Carbon|null $created_at
 */
class Event extends PackageEvent implements AuditableContract
{
    /** @use HasFactory<EventFactory> */
    use AuditsModelChanges, HasAddresses, HasDonationChannels, HasFactory, HasPrimaryAddressAccessors, HasStates, HasTags, KeepsDeletedModels, Searchable;

    protected static string $ownerScopeConfigKey = '';

    protected static bool $ownerScopeEnabledByDefault = false;

    /**
     * Statuses visible on public listings and detail pages.
     *
     * @var list<string>
     */
    public const array PUBLIC_STATUSES = ['approved', 'published', 'pending', 'cancelled'];

    /**
     * Statuses that still allow engagement actions (save/going).
     *
     * @var list<string>
     */
    public const array ENGAGEABLE_STATUSES = ['approved', 'published', 'pending'];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array{width: int, height: int}|null
     */
    private ?array $resolvedPosterDimensions = null;

    /**
     * @var array<string, mixed>
     */
    private array $pendingPrimaryOccurrence = [];

    /**
     * @var Collection<int, Language>|null
     */
    private ?Collection $resolvedLanguageCache = null;

    /**
     * @var list<string>
     */
    private const array MetadataBackedAttributes = [
        'user_id',
        'institution_id',
        'submitter_id',
        'parent_event_id',
        'event_structure',
        'schedule_kind',
        'schedule_state',
        'timing_mode',
        'is_active',
        'views_count',
        'registrations_count',
        'saves_count',
        'going_count',
    ];

    /**
     * @var array<string, string|null>
     */
    private array $pendingLinkWrites = [];

    /**
     * @var array<string, string|null>
     */
    private array $pendingTimeExpressionWrites = [];

    private const array PRAYER_TIME_FIELDS = ['prayer_reference', 'prayer_offset', 'prayer_display_text'];

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

    /**
     * @var array<string, mixed>
     */
    private array $pendingLocationWrites = [];

    private const array ATTRIBUTE_FIELDS = ['is_featured', 'is_priority', 'escalated_at'];

    #[\Override]
    protected static function booted(): void
    {
        static::saved(function (Event $event): void {
            $event->syncPrimaryOccurrenceFromPendingState();
            $event->syncUrlLinks();
            $event->syncTimeExpressions();
            $event->syncAudiences();
            $event->syncAttributes();
            $event->syncLocation();
        });

        static::deleting(function (Event $event) {
            $event->childEvents()->each(function (Event $childEvent): void {
                $childEvent->delete();
            });

            $event->members()->detach();
            $event->involvements()->delete();
            $event->accessPolicies()->delete();
            $event->keyPeople()->delete();
            $event->references()->detach();
            $event->savedBy()->detach();
            $event->goingBy()->detach();

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
        'user_id',
        'institution_id',
        'submitter_id',
        'venue_id',
        'space_id',
        'parent_event_id',

        'title',
        'slug',
        'event_structure',
        'description',
        'escalated_at',
        'starts_at',
        'ends_at',
        'schedule_kind',
        'schedule_state',
        'timezone',
        'timing_mode',
        'prayer_reference',
        'prayer_offset',
        'prayer_display_text',
        'live_url',
        'event_url',
        'recording_url',
        'event_type',
        'gender',
        'age_group',
        'children_allowed',
        'event_format',
        'visibility',
        'status',
        'views_count',
        'saves_count',
        'registrations_count',
        'going_count',
        'published_at',
        'summary',
        'type',
        'delivery_mode',
        'default_venue_id',
        'pricing_mode',
        'registration_mode',
        'issue_passes_for_free',
        'metadata',
        'is_active',
        'is_featured',
        'is_muslim_only',
        'is_priority',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'visibility' => EventVisibility::class,
            'published_at' => 'datetime',
            'description' => 'array',
            'metadata' => 'array',
            'issue_passes_for_free' => 'boolean',
        ];
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
        if ($key === 'starts_at' || $key === 'ends_at') {
            $this->pendingPrimaryOccurrence[$key] = $value;
            $this->setMetadataValue($key, $value);

            return $this;
        }

        if ($key === 'venue_id') {
            $this->setMetadataValue($key, $value);

            return parent::setAttribute('default_venue_id', $value);
        }

        if ($key === 'event_format') {
            $this->setMetadataValue($key, $value);

            return parent::setAttribute('delivery_mode', $this->enumValue($value) ?? $value);
        }

        if ($key === 'event_type') {
            $this->setMetadataValue($key, $value);

            $type = $this->firstEnumValue($value);

            return parent::setAttribute('type', $type);
        }

        if (in_array($key, self::MetadataBackedAttributes, true)) {
            $this->setMetadataValue($key, $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    #[\Override]
    public function getAttribute($key): mixed
    {
        if (in_array($key, ['starts_at', 'ends_at'], true)) {
            return $this->primaryOccurrenceDate($key) ?? $this->dateFromMetadata($key);
        }

        if ($key === 'venue_id') {
            return parent::getAttribute('default_venue_id') ?? $this->legacyMetadataValue($key);
        }

        if ($key === 'event_format') {
            $value = parent::getAttribute('delivery_mode') ?? $this->legacyMetadataValue($key);

            return EventFormat::tryFrom((string) $value) ?? $value;
        }

        if ($key === 'event_type') {
            return $this->legacyMetadataValue($key) ?? array_filter([(string) parent::getAttribute('type')]);
        }

        if (in_array($key, self::MetadataBackedAttributes, true)) {
            return $this->legacyMetadataValue($key);
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
    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class)->orderBy('starts_at')->orderBy('created_at');
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

    public function setPrimaryOrganizer(Institution|Speaker|null $organizer): static
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
            EventInvolvement::query()->create([
                'id' => (string) Str::uuid(),
                'event_id' => (string) $this->getKey(),
                'event_occurrence_id' => null,
                'event_session_id' => null,
                'involveable_type' => $organizer::class,
                'involveable_id' => (string) $organizer->getKey(),
                'role_code' => 'organizer',
                'status' => 'confirmed',
                'visibility' => 'public',
                'prominence' => 0,
                'is_featured' => false,
                'is_primary' => true,
                'sort_order' => 0,
            ]);
        }

        $this->unsetRelation('primaryOrganizerInvolvement');

        return $this;
    }

    private const array LINK_TYPE_MAP = [
        'live_url' => 'streaming',
        'event_url' => 'external',
        'recording_url' => 'recording',
    ];

    private const array LINK_TYPE_FIELDS = ['live_url', 'event_url', 'recording_url'];

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

    public function getPrayerReferenceAttribute(mixed $value): ?string
    {
        if (array_key_exists('prayer_reference', $this->pendingTimeExpressionWrites)) {
            $val = $this->pendingTimeExpressionWrites['prayer_reference'];

            return $val instanceof \BackedEnum ? $val->value : $val;
        }

        return $this->prayerExpression()?->anchor_code;
    }

    public function getPrayerOffsetAttribute(mixed $value): ?string
    {
        if (array_key_exists('prayer_offset', $this->pendingTimeExpressionWrites)) {
            return $this->pendingTimeExpressionWrites['prayer_offset'];
        }

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
        if (array_key_exists('prayer_display_text', $this->pendingTimeExpressionWrites)) {
            return $this->pendingTimeExpressionWrites['prayer_display_text'];
        }

        return $this->prayerExpression()?->display_label;
    }

    public function setPrayerReferenceAttribute(mixed $value): void
    {
        $this->pendingTimeExpressionWrites['prayer_reference'] = $value;
    }

    public function setPrayerOffsetAttribute(mixed $value): void
    {
        $this->pendingTimeExpressionWrites['prayer_offset'] = $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function setPrayerDisplayTextAttribute(?string $value): void
    {
        $this->pendingTimeExpressionWrites['prayer_display_text'] = $value;
    }

    private function prayerExpression(): ?EventTimeExpression
    {
        if ($this->relationLoaded('timeExpressions')) {
            return $this->timeExpressions->first(fn (EventTimeExpression $e) => $e->anchor_type === 'prayer');
        }

        return $this->timeExpressions()->where('anchor_type', 'prayer')->first();
    }

    public function syncTimeExpressions(): void
    {
        if ($this->pendingTimeExpressionWrites === []) {
            return;
        }

        $timingMode = $this->legacyMetadataValue('timing_mode');

        if ($timingMode !== TimingMode::PrayerRelative->value) {
            $this->timeExpressions()->where('anchor_type', 'prayer')->delete();
            $this->pendingTimeExpressionWrites = [];

            return;
        }

        $prayerRef = $this->pendingTimeExpressionWrites['prayer_reference'] ?? $this->prayerExpression()?->anchor_code;

        if ($prayerRef instanceof \BackedEnum) {
            $prayerRef = $prayerRef->value;
        }

        $prayerOffset = $this->pendingTimeExpressionWrites['prayer_offset'] ?? null;
        $offsetMinutes = 5;
        $relation = 'after';

        if ($prayerOffset !== null) {
            $offset = PrayerOffset::tryFrom($prayerOffset);

            if ($offset !== null) {
                $minutes = $offset->minutes();
                $relation = $minutes >= 0 ? 'after' : 'before';
                $offsetMinutes = abs($minutes);
            }
        }

        $displayLabel = $this->pendingTimeExpressionWrites['prayer_display_text']
            ?? $this->prayerExpression()?->display_label;

        if ($prayerRef !== null || $displayLabel !== null) {
            EventTimeExpression::updateOrCreate(
                ['event_id' => $this->id, 'anchor_type' => 'prayer'],
                [
                    'time_mode' => 'prayer_relative',
                    'anchor_type' => 'prayer',
                    'anchor_code' => $prayerRef,
                    'relation' => $relation,
                    'offset_minutes' => $offsetMinutes,
                    'display_label' => $displayLabel,
                    'resolver_class' => PrayerTimeExpressionResolver::class,
                ],
            );
        }

        $this->pendingTimeExpressionWrites = [];
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

        $this->pendingAudienceWrites = [];
        $this->pendingAudienceProfileWrites = [];
    }

    // ─── EventAttribute (is_featured, is_priority, escalated_at) ────────────

    public function getIsFeaturedAttribute(mixed $value): ?bool
    {
        if (array_key_exists('is_featured', $this->pendingAttributeWrites)) {
            return $this->pendingAttributeWrites['is_featured'];
        }

        $attr = $this->relationLoaded('attributes')
            ? $this->attributes?->firstWhere('attribute_key', 'is_featured')
            : EventAttribute::where('event_id', $this->id)->where('attribute_key', 'is_featured')->value('attribute_value');

        return $attr === null ? null : $attr !== '0';
    }

    public function getIsPriorityAttribute(mixed $value): ?bool
    {
        if (array_key_exists('is_priority', $this->pendingAttributeWrites)) {
            return $this->pendingAttributeWrites['is_priority'];
        }

        $val = $this->relationLoaded('attributes')
            ? $this->attributes?->firstWhere('attribute_key', 'is_priority')?->attribute_value
            : EventAttribute::where('event_id', $this->id)->where('attribute_key', 'is_priority')->value('attribute_value');

        return $val === null ? null : $val !== '0';
    }

    public function getEscalatedAtAttribute(mixed $value): mixed
    {
        if (array_key_exists('escalated_at', $this->pendingAttributeWrites)) {
            return $this->pendingAttributeWrites['escalated_at'];
        }

        return $this->dateFromMetadata('escalated_at');
    }

    public function setIsFeaturedAttribute(mixed $value): void
    {
        $this->pendingAttributeWrites['is_featured'] = $value === null ? null : (bool) $value;
    }

    public function setIsPriorityAttribute(mixed $value): void
    {
        $this->pendingAttributeWrites['is_priority'] = $value === null ? null : (bool) $value;
    }

    public function setEscalatedAtAttribute(mixed $value): void
    {
        $this->pendingAttributeWrites['escalated_at'] = $value;
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
                'is_featured', 'is_priority' => EventAttribute::updateOrCreate(
                    ['event_id' => $this->id, 'attribute_key' => $key],
                    ['attribute_value' => $value ? '1' : '0'],
                ),
                'escalated_at' => EventAttribute::updateOrCreate(
                    ['event_id' => $this->id, 'attribute_key' => $key],
                    ['attribute_value' => $value instanceof CarbonInterface ? $value->toIso8601String() : $value],
                ),
                default => null,
            };
        }

        $this->pendingAttributeWrites = [];
    }

    // ─── EventLocation (space_id) ───────────────────────────────────────────

    public function getSpaceIdAttribute(mixed $value): ?string
    {
        if (array_key_exists('space_id', $this->pendingLocationWrites)) {
            return $this->pendingLocationWrites['space_id'];
        }

        if ($this->relationLoaded('locations')) {
            return $this->locations->first()?->venue_space_id;
        }

        return $this->locations()->value('venue_space_id');
    }

    public function setSpaceIdAttribute(?string $value): void
    {
        $this->pendingLocationWrites['space_id'] = $value;
    }

    public function syncLocation(): void
    {
        if ($this->pendingLocationWrites === []) {
            return;
        }

        $spaceId = $this->pendingLocationWrites['space_id'] ?? null;

        if ($spaceId !== null && $spaceId !== '') {
            EventLocation::updateOrCreate(
                ['event_id' => $this->id],
                ['venue_space_id' => $spaceId, 'location_role' => 'main'],
            );
        } else {
            EventLocation::where('event_id', $this->id)->delete();
        }

        $this->pendingLocationWrites = [];
    }

    private function syncSingleAudience(string $type, mixed $value): void
    {
        if ($value !== null && $value !== '' && $value !== false) {
            EventAudience::updateOrCreate(
                ['event_id' => $this->id, 'audience_type' => $type],
                ['value' => (string) $value],
            );
        } else {
            EventAudience::where('event_id', $this->id)
                ->where('audience_type', $type)
                ->delete();
        }
    }

    private function syncAgeGroupAudience(mixed $value): void
    {
        EventAudience::where('event_id', $this->id)
            ->where('audience_type', 'age_group')
            ->delete();

        if ($value === null || $value === [] || $value === '') {
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
    }

    private function syncPrimaryOccurrenceFromPendingState(): void
    {
        $startsAt = $this->pendingPrimaryOccurrence['starts_at'] ?? $this->legacyMetadataValue('starts_at');
        $endsAt = $this->pendingPrimaryOccurrence['ends_at'] ?? $this->legacyMetadataValue('ends_at');

        if ($startsAt === null && $endsAt === null) {
            return;
        }

        $occurrence = $this->occurrences()->withoutGlobalScopes()->first() ?? new EventOccurrence([
            'event_id' => $this->getKey(),
        ]);

        $occurrence->fill([
            'event_id' => $this->getKey(),
            'title' => $this->title,
            'slug' => $this->slug,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $this->timezone,
            'status' => $this->occurrenceStatusValue(),
            'visibility' => $this->enumValue($this->visibility) ?? 'public',
            'delivery_mode' => $this->enumValue($this->event_format) ?? $this->delivery_mode,
            'published_at' => $this->published_at,
            'pricing_mode' => $this->pricing_mode ?? 'free',
            'registration_mode' => $this->registration_mode ?? 'none',
            'issue_passes_for_free' => $this->issue_passes_for_free ?? true,
            'metadata' => array_filter([
                'source' => 'app_event_primary_occurrence',
                'schedule_kind' => $this->legacyMetadataValue('schedule_kind'),
                'schedule_state' => $this->legacyMetadataValue('schedule_state'),
                'timing_mode' => $this->legacyMetadataValue('timing_mode'),
                'prayer_reference' => $this->prayer_reference,
                'prayer_offset' => $this->prayer_offset,
                'prayer_display_text' => $this->prayer_display_text,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);

        $occurrence->save();
        $this->pendingPrimaryOccurrence = [];
    }

    private function occurrenceStatusValue(): string
    {
        return match ((string) $this->status) {
            'approved', 'published' => EventOccurrence::PUBLISHED,
            'cancelled' => EventOccurrence::CANCELLED,
            default => EventOccurrence::SCHEDULED,
        };
    }

    private function primaryOccurrenceDate(string $key): mixed
    {
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

    private function setMetadataValue(string $key, mixed $value): void
    {
        $metadata = $this->attributes['metadata'] ?? null;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata[$key] = $this->metadataSerializableValue($value);

        parent::setAttribute('metadata', $metadata);
    }

    private function legacyMetadataValue(string $key): mixed
    {
        $metadata = $this->attributes['metadata'] ?? null;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        if (! is_array($metadata) || ! array_key_exists($key, $metadata)) {
            return null;
        }

        return $metadata[$key];
    }

    private function dateFromMetadata(string $key): ?Carbon
    {
        $value = $this->legacyMetadataValue($key);

        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private function metadataSerializableValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof Collection) {
            return $value
                ->map(fn (mixed $entry): mixed => $this->metadataSerializableValue($entry))
                ->values()
                ->all();
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $entry): mixed => $this->metadataSerializableValue($entry))
                ->values()
                ->all();
        }

        return $value;
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum && is_string($value->value)) {
            return $value->value;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private function firstEnumValue(mixed $value): ?string
    {
        if ($value instanceof Collection) {
            return $this->firstEnumValue($value->all());
        }

        if (is_array($value)) {
            foreach ($value as $entry) {
                $resolved = $this->enumValue($entry);

                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }

        return $this->enumValue($value);
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
            ->where("{$table}.is_active", true);
    }

    /**
     * Scope a query to only include standalone events and child events.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function discoverable(Builder $query): void
    {
        $table = $query->getModel()->getTable();

        $query->where("{$table}.event_structure", '!=', EventStructure::ParentProgram->value);
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
        return $this->is_active
            && in_array((string) $this->status, self::PUBLIC_STATUSES, true)
            && $this->eventStructure()->isDiscoverable()
            && $this->visibility === EventVisibility::Public;
    }

    public function isPubliclyReachable(): bool
    {
        $visibility = $this->visibility;
        $visibleByLink = $visibility instanceof EventVisibility
            ? in_array($visibility, [EventVisibility::Public, EventVisibility::Unlisted], true)
            : in_array((string) $visibility, [EventVisibility::Public->value, EventVisibility::Unlisted->value], true);

        return $this->is_active
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

    private function resolveReachableReplacementEvent(?self $event): ?self
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
            'event_structure',
            'parent_event_id',
            'starts_at',
            'ends_at',
            'language',
            'event_type',
            'gender',
            'age_group',
            'children_allowed',
            'event_format',
            'status',
            'visibility',
            'institution_id',
            'venue_id',
            'saves_count',
            'registrations_count',
            'is_active',
        ]);
    }

    public function getPublicChangeBadgeLabelAttribute(): ?string
    {
        if ((string) $this->status === 'cancelled') {
            return EventChangeType::Cancelled->publicBadgeLabel();
        }

        if ($this->schedule_state === ScheduleState::Postponed) {
            return EventChangeType::Postponed->publicBadgeLabel();
        }

        $notice = $this->latestPublishedChangeAnnouncement;

        if (! $notice instanceof EventChangeAnnouncement) {
            return null;
        }

        return $notice->type->publicBadgeLabel();
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query
            ->with(['institution', 'institution.addresses', 'venue', 'venue.addresses', 'speakers', 'keyPeople.speaker', 'tags', 'references'])
            ->where('events.is_active', true)
            ->whereIn('events.status', self::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public)
            ->where('events.event_structure', '!=', EventStructure::ParentProgram->value);
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

        $this->loadMissing(['institution', 'institution.addresses', 'venue', 'venue.addresses', 'speakers', 'keyPeople.speaker', 'tags', 'references']);
        $venueAddress = $this->venue?->primaryAddress();
        $institutionAddress = $this->institution?->primaryAddress();
        $institution = $this->institution;
        $venue = $this->venue;
        $gender = $this->gender;
        $eventFormat = $this->event_format;
        $visibility = $this->visibility;

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

        /** @var \Illuminate\Database\Eloquent\Collection<int, Tag> $tags */
        $tags = $this->tags;

        $topicIds = $tags
            ->filter(fn (Tag $tag): bool => in_array($tag->type, [TagType::Discipline->value, TagType::Issue->value], true))
            ->whereIn('status', ['verified', 'pending'])
            ->pluck('id')
            ->values()
            ->all();

        $domainTagIds = $tags
            ->filter(fn (Tag $tag): bool => $tag->type === TagType::Domain->value)
            ->whereIn('status', ['verified', 'pending'])
            ->pluck('id')
            ->values()
            ->all();

        $sourceTagIds = $tags
            ->filter(fn (Tag $tag): bool => $tag->type === TagType::Source->value)
            ->whereIn('status', ['verified', 'pending'])
            ->pluck('id')
            ->values()
            ->all();

        /** @var \Illuminate\Database\Eloquent\Collection<int, EventKeyPerson> $keyPeople */
        $keyPeople = $this->keyPeople;

        /** @var list<string> $keyPersonRoles */
        $keyPersonRoles = [];

        foreach ($keyPeople as $keyPerson) {
            $role = $keyPerson->role;

            if ($role instanceof EventKeyPersonRole && ! in_array($role->value, $keyPersonRoles, true)) {
                $keyPersonRoles[] = $role->value;
            }
        }

        $keyPersonSpeakerIds = $keyPeople
            ->pluck('speaker_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->unique()
            ->values()
            ->all();

        $personInChargeIds = $keyPeople
            ->where('role', EventKeyPersonRole::PersonInCharge)
            ->pluck('speaker_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $personInChargeNames = $keyPeople
            ->where('role', EventKeyPersonRole::PersonInCharge)
            ->map(function (EventKeyPerson $keyPerson): string {
                if ($keyPerson->speaker instanceof Speaker) {
                    $searchableName = trim((string) $keyPerson->speaker->searchable_name);

                    return $searchableName !== '' ? $searchableName : (string) $keyPerson->speaker->name;
                }

                return (string) ($keyPerson->name ?? '');
            })
            ->filter(fn (string $name): bool => trim($name) !== '')
            ->unique()
            ->values()
            ->implode(', ');

        $moderatorIds = $keyPeople
            ->where('role', EventKeyPersonRole::Moderator)
            ->pluck('speaker_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $imamIds = $keyPeople
            ->where('role', EventKeyPersonRole::Imam)
            ->pluck('speaker_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $khatibIds = $keyPeople
            ->where('role', EventKeyPersonRole::Khatib)
            ->pluck('speaker_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $bilalIds = $keyPeople
            ->where('role', EventKeyPersonRole::Bilal)
            ->pluck('speaker_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();

        $issueTagIds = $tags
            ->filter(fn (Tag $tag): bool => $tag->type === TagType::Issue->value)
            ->whereIn('status', ['verified', 'pending'])
            ->pluck('id')
            ->values()
            ->all();

        $array = [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description_text,
            'slug' => $this->slug,
            'event_structure' => $this->eventStructure()->value,
            'parent_event_id' => $this->parent_event_id,
            'speaker_names' => $this->speakerKeyPeople
                ->map(fn (EventKeyPerson $keyPerson): string => $keyPerson->speaker !== null ? $keyPerson->speaker->name : (string) ($keyPerson->name ?? ''))
                ->filter(fn (string $name): bool => $name !== '')
                ->implode(', '),
            'institution_name' => $institution instanceof Institution ? $institution->name : '',
            'venue_name' => $venue instanceof Venue ? $venue->name : '',
            'country_code' => $venueAddress->country_code ?? $institutionAddress?->country_code,
            'city' => $venueAddress->city ?? $institutionAddress?->city,
            'state' => $venueAddress->state ?? $institutionAddress?->state,
            'postcode' => $venueAddress->postcode ?? $institutionAddress?->postcode,
            'language_codes' => $languageCodes,
            'event_type' => $this->normalizedEventTypeValues(),
            'gender' => $gender instanceof EventGenderRestriction ? $gender->value : ((is_string($gender) && $gender !== '') ? $gender : 'all'),
            'age_group' => $ageGroupValues,
            'event_format' => $eventFormat instanceof EventFormat ? $eventFormat->value : ((is_string($eventFormat) && $eventFormat !== '') ? $eventFormat : 'physical'),
            'children_allowed' => $this->children_allowed ?? true,
            'is_active' => (bool) $this->is_active,
            'status' => (string) $this->status,
            'visibility' => $visibility instanceof EventVisibility ? $visibility->value : ((is_string($visibility) && $visibility !== '') ? $visibility : 'public'),
            'topic_ids' => $topicIds,
            'domain_tag_ids' => $domainTagIds,
            'source_tag_ids' => $sourceTagIds,
            'issue_tag_ids' => $issueTagIds,
            'reference_ids' => $this->references->pluck('id')->values()->all(),
            'speaker_ids' => $this->speakerKeyPeople
                ->pluck('speaker_id')
                ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
                ->values()
                ->all(),
            'key_person_roles' => $keyPersonRoles,
            'key_person_speaker_ids' => $keyPersonSpeakerIds,
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
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<User, $this, EventUser>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_user')
            ->using(EventUser::class)
            ->withPivot(['joined_at'])
            ->withTimestamps();
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
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function childEvents(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id')->orderBy('created_at');
    }

    /**
     * @return BelongsToMany<Series, $this, EventSeries, 'pivot'>
     */
    public function series(): BelongsToMany
    {
        return $this->belongsToMany(
            Series::class,
            config('events.database.tables.event_series_items', 'event_series_items'),
            'event_id',
            'event_series_id',
        )
            ->using(EventSeries::class)
            ->withPivot('id', 'seriesable_type', 'seriesable_id', 'sort_order')
            ->wherePivot('seriesable_type', self::class)
            ->withPivotValue('seriesable_type', self::class)
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    // EventType relationship removed in favor of Enum

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
    public function speakerKeyPeople(): HasMany
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

    public function eventStructure(): EventStructure
    {
        $eventStructure = $this->event_structure;

        if ($eventStructure instanceof EventStructure) {
            return $eventStructure;
        }

        return EventStructure::tryFrom((string) $eventStructure) ?? EventStructure::Standalone;
    }

    public function isStandaloneEvent(): bool
    {
        return $this->eventStructure() === EventStructure::Standalone;
    }

    public function isParentProgram(): bool
    {
        return $this->eventStructure() === EventStructure::ParentProgram;
    }

    public function isChildEvent(): bool
    {
        return $this->eventStructure() === EventStructure::ChildEvent;
    }

    public function isSchedulable(): bool
    {
        return $this->eventStructure()->isSchedulable();
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
     * @return HasOne<EventSettings, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(EventSettings::class);
    }

    /**
     * @return BelongsToMany<Speaker, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'event_involvements', 'event_id', 'speaker_id')
            ->using(EventKeyPersonPivot::class)
            ->wherePivot('role_code', EventKeyPersonRole::Speaker->value)
            ->withPivotValue('role_code', EventKeyPersonRole::Speaker->value)
            ->withPivot(['id', 'role_code', 'name', 'sort_order', 'is_public', 'notes'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return BelongsToMany<Reference, $this>
     */
    public function references(): BelongsToMany
    {
        return $this->belongsToMany(Reference::class, 'event_reference')
            ->withPivot('order_column')
            ->withTimestamps()
            ->orderByPivot('order_column');
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
        return $this->hasMany(EventChangeAnnouncement::class);
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
        return $this->hasMany(EventChangeAnnouncement::class, 'replacement_event_id');
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
            ->where("{$table}.metadata->status", EventChangeStatus::Published->value)
            ->whereNull("{$table}.archived_at");

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
     * @return BelongsToMany<User, $this>
     */
    public function goingBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_attendees')->withTimestamps();
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
            ->performOnCollections('cover', 'poster', 'gallery')
            ->fit(Fit::Crop, 600, 400)
            ->sharpen(10)
            ->format('webp');

        $this->addMediaConversion('card')
            ->performOnCollections('cover', 'poster')
            ->fit(Fit::Max, 960, 1200)
            ->format('webp');

        $this->addMediaConversion('preview')
            ->performOnCollections('cover', 'poster')
            ->fit(Fit::Max, 1400, 1800)
            ->format('webp');
    }

    /**
     * Check if this event uses prayer-relative timing.
     */
    public function isPrayerRelative(): bool
    {
        $timingMode = $this->timing_mode;

        if ($timingMode instanceof TimingMode) {
            return $timingMode === TimingMode::PrayerRelative;
        }

        return (string) $timingMode === TimingMode::PrayerRelative->value;
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
        $venueAddress = $this->venue?->addressModel;

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

    /**
     * @return list<string>
     */
    private function normalizedEventTypeValues(): array
    {
        $eventType = $this->event_type;

        if ($eventType instanceof Collection) {
            return $eventType
                ->map(fn (EventType $value): string => $value->value)
                ->filter(fn (string $value): bool => $value !== '')
                ->values()
                ->all();
        }

        if (is_array($eventType)) {
            return array_values(array_filter(array_map(strval(...), $eventType), static fn (string $value): bool => $value !== ''));
        }

        if ($eventType instanceof EventType) {
            return [$eventType->value];
        }

        if (is_string($eventType) && $eventType !== '') {
            return [$eventType];
        }

        return [];
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

        if ($this->organizer instanceof Speaker && $memberPermissions->canSpeaker($user, $permission, $this->organizer)) {
            return true;
        }

        return $this->institution instanceof Institution && $memberPermissions->canInstitution($user, $permission, $this->institution);
    }
}
