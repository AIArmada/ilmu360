<?php

namespace App\Models;

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
use App\Enums\ScheduleKind;
use App\Enums\ScheduleState;
use App\Enums\TagType;
use App\Enums\TimingMode;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasAddress;
use App\Models\Concerns\HasDonationChannels;
use App\Models\Concerns\HasLanguages;
use App\States\EventStatus\EventStatus;
use App\States\EventStatus\Pending;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Timezone\UserDateTimeFormatter;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\Searchable;
use Nnjeim\World\Models\Language;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\ModelStates\HasStates;
use Spatie\Tags\HasTags;

/**
 * @property string $id
 * @property string $title
 * @property string $slug
 * @property array<string, mixed>|string|null $description
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property EventStatus|string $status
 * @property ScheduleState|string|null $schedule_state
 * @property EventVisibility|string|null $visibility
 * @property EventFormat|string|null $event_format
 * @property EventStructure|string $event_structure
 * @property EventGenderRestriction|string|null $gender
 * @property Collection<int, EventAgeGroup>|array<int, string>|null $age_group
 * @property Collection<int, EventType>|array<int, string>|null $event_type
 * @property bool $is_active
 * @property-read Address|null $address
 * @property-read Address|null $addressModel
 * @property-read Institution|null $institution
 * @property-read Venue|null $venue
 * @property-read EventChangeAnnouncement|null $latestPublishedChangeAnnouncement
 * @property-read EventChangeAnnouncement|null $latestPublishedReplacementAnnouncement
 * @property-read string|null $reference_study_subtitle
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventKeyPerson> $keyPeople
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Speaker> $speakers
 */
class Event extends Model implements AuditableContract, HasMedia
{
    /** @use HasFactory<EventFactory> */
    use AuditsModelChanges, HasAddress, HasDonationChannels, HasFactory, HasLanguages, HasStates, HasTags, HasUuids, InteractsWithMedia, KeepsDeletedModels, Searchable;

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

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array{width: int, height: int}|null
     */
    private ?array $resolvedPosterDimensions = null;

    #[\Override]
    protected static function booted(): void
    {
        static::deleting(function (Event $event) {
            $event->childEvents()->each(function (Event $childEvent): void {
                $childEvent->delete();
            });

            $event->members()->detach();
            $event->keyPeople()->delete();
            $event->references()->detach();
            $event->savedBy()->detach();
            $event->goingBy()->detach();

            $event->registrations()->each(function (Registration $registration): void {
                $registration->delete();
            });
            $event->checkins()->each(function (EventCheckin $checkin): void {
                $checkin->delete();
            });
            $event->submissions()->each(function (EventSubmission $submission): void {
                $submission->delete();
            });
            $event->moderationReviews()->each(function (ModerationReview $review): void {
                $review->delete();
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
        'organizer_type',
        'organizer_id',

        'title',
        'slug',
        'event_structure',
        'description',
        'starts_at',
        'ends_at',
        'schedule_kind',
        'schedule_state',
        'timezone',
        'timing_mode',
        'prayer_reference',
        'prayer_offset',
        'prayer_display_text',
        'event_type',
        'gender',
        'age_group',
        'children_allowed',
        'event_format',
        'visibility',
        'status',
        'live_url',
        'event_url',
        'recording_url',
        'views_count',
        'saves_count',
        'registrations_count',
        'going_count',
        'published_at',
        'escalated_at',
        'is_priority',
        'is_featured',
        'is_active',
        'is_muslim_only',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'description' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'schedule_kind' => ScheduleKind::class,
            'schedule_state' => ScheduleState::class,
            'event_structure' => EventStructure::class,
            'timing_mode' => TimingMode::class,
            'prayer_reference' => PrayerReference::class,
            'prayer_offset' => PrayerOffset::class,
            'gender' => EventGenderRestriction::class,
            'age_group' => AsEnumCollection::of(EventAgeGroup::class),
            'event_format' => EventFormat::class,
            'event_type' => AsEnumCollection::of(EventType::class),
            'visibility' => EventVisibility::class,
            'children_allowed' => 'boolean',
            'views_count' => 'integer',
            'saves_count' => 'integer',
            'registrations_count' => 'integer',
            'going_count' => 'integer',
            'published_at' => 'datetime',
            'escalated_at' => 'datetime',
            'is_priority' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'is_muslim_only' => 'boolean',
        ];
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

        $query->where("{$table}.is_active", true)
            ->whereIn("{$table}.status", self::PUBLIC_STATUSES)
            ->where("{$table}.visibility", EventVisibility::Public)
            ->where("{$table}.event_structure", '!=', EventStructure::ParentProgram->value);
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
            ->with(['institution', 'institution.address', 'venue', 'venue.address', 'speakers', 'keyPeople.speaker', 'tags', 'references', 'languages'])
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

        $this->loadMissing(['institution', 'institution.address', 'venue', 'venue.address', 'speakers', 'keyPeople.speaker', 'tags', 'references', 'languages']);
        $venueAddress = $this->venue?->addressModel;
        $institutionAddress = $this->institution?->addressModel;
        $institution = $this->institution;
        $venue = $this->venue;
        $gender = $this->gender;
        $eventFormat = $this->event_format;
        $visibility = $this->visibility;

        $languageCodes = $this->languages
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
            'state_id' => $venueAddress instanceof Address
                ? $venueAddress->state_id
                : ($institutionAddress instanceof Address
                    ? $institutionAddress->state_id
                    : ''),
            'district_id' => $venueAddress instanceof Address
                ? $venueAddress->district_id
                : ($institutionAddress instanceof Address
                    ? $institutionAddress->district_id
                    : ''),
            'subdistrict_id' => $venueAddress instanceof Address
                ? $venueAddress->subdistrict_id
                : ($institutionAddress instanceof Address
                    ? $institutionAddress->subdistrict_id
                    : ''),
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

        if ($venueAddress instanceof Address && $venueAddress->lat !== null && $venueAddress->lng !== null) {
            $array['location'] = [(float) $venueAddress->lat, (float) $venueAddress->lng];
        } elseif ($institutionAddress instanceof Address && $institutionAddress->lat !== null && $institutionAddress->lng !== null) {
            $array['location'] = [(float) $institutionAddress->lat, (float) $institutionAddress->lng];
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
        return $this->belongsTo(Venue::class);
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
        return $this->hasMany(self::class, 'parent_event_id')->orderBy('starts_at')->orderBy('created_at');
    }

    /**
     * @return BelongsToMany<Series, $this, EventSeries, 'pivot'>
     */
    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'event_series')
            ->using(EventSeries::class)
            ->withPivot('id', 'order_column')
            ->withTimestamps()
            ->orderByPivot('order_column');
    }

    // EventType relationship removed in favor of Enum

    /**
     * @return HasOne<EventSettings, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(EventSettings::class);
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function keyPeople(): HasMany
    {
        return $this->hasMany(EventKeyPerson::class)->orderBy('order_column')->orderBy('created_at');
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function speakerKeyPeople(): HasMany
    {
        return $this->keyPeople()->where('role', EventKeyPersonRole::Speaker->value);
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function nonSpeakerKeyPeople(): HasMany
    {
        return $this->keyPeople()->where('role', '!=', EventKeyPersonRole::Speaker->value);
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

    /**
     * @return BelongsToMany<Speaker, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'event_key_people', 'event_id', 'speaker_id')
            ->using(EventKeyPersonPivot::class)
            ->wherePivot('role', EventKeyPersonRole::Speaker->value)
            ->withPivotValue('role', EventKeyPersonRole::Speaker->value)
            ->withPivot(['id', 'role', 'name', 'order_column', 'is_public', 'notes'])
            ->withTimestamps()
            ->orderByPivot('order_column');
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
        return $this->hasMany(ModerationReview::class);
    }

    /**
     * @return HasOne<ModerationReview, $this>
     */
    public function latestModerationReview(): HasOne
    {
        return $this->hasOne(ModerationReview::class)
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
            ->where("{$table}.status", EventChangeStatus::Published->value)
            ->whereNull("{$table}.retracted_at");

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
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /**
     * @return HasMany<EventCheckin, $this>
     */
    public function checkins(): HasMany
    {
        return $this->hasMany(EventCheckin::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_saves')->withTimestamps();
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
        return $this->timing_mode === TimingMode::PrayerRelative;
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

        if ($venueAddress instanceof Address && $venueAddress->lat !== null && $venueAddress->lng !== null) {
            return [
                'lat' => (float) $venueAddress->lat,
                'lng' => (float) $venueAddress->lng,
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

        $language = $this->languages->first();

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
     * @return MorphTo<Model, $this>
     */
    public function organizer(): MorphTo
    {
        return $this->morphTo();
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
