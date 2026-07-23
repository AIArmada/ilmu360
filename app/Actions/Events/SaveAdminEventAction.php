<?php

namespace App\Actions\Events;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Seating\Models\SeatMap;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Enums\PrayerOffset;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Series;
use App\Models\Space;
use App\Models\User;
use App\Services\ModerationService;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Events\OrganizerResolver;
use App\Support\Media\ModelMediaSyncService;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveAdminEventAction
{
    use AsAction;

    public function __construct(
        private GenerateEventSlugAction $generateEventSlugAction,
        private ModelMediaSyncService $mediaSyncService,
        private SyncEventResourceRelationsAction $syncEventResourceRelationsAction,
        private ModerationService $moderationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function defaultsForCreate(): array
    {
        return [
            'status' => 'draft',
            'timezone' => 'Asia/Kuala_Lumpur',
            'schedule_kind' => ScheduleKind::Single->value,
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_category_ids' => array_slice(array_keys(app(EventCategoryCatalog::class)->options()), 0, 1),
            'children_allowed' => false,
            'is_muslim_only' => false,
            'references' => [],
            'series' => [],
            'languages' => [],
            'domain_tags' => [],
            'discipline_tags' => [],
            'source_tags' => [],
            'issue_tags' => [],
            'primary_organizer_id' => null,
            'speakers' => [],
            'other_key_people' => [],
            'registration_required' => false,
            'registration_mode' => RegistrationMode::None->value,
            'is_featured' => false,
            'clear_cover' => false,
            'clear_poster' => false,
            'clear_gallery' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formStateForRecord(Event $event): array
    {
        $event->loadMissing(['references:id,title', 'series:id,title', 'classifications', 'keyPeople.speaker', 'languages:id,event_id', 'accessPolicy', 'primaryLocation.venueSpace']);

        $timeFields = AdminEventTimeMapper::injectFormTimeFields([
            'starts_at' => $event->starts_at?->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),
            'timezone' => $event->timezone,
            'schedule_kind' => $event->schedule_kind instanceof ScheduleKind ? $event->schedule_kind->value : $event->schedule_kind,
            'timing_mode' => $event->timing_mode instanceof BackedEnum ? $event->timing_mode->value : $event->timing_mode,
            'prayer_reference' => $event->prayer_reference instanceof BackedEnum ? $event->prayer_reference->value : $event->prayer_reference,
            'prayer_offset' => $event->prayer_offset instanceof BackedEnum ? $event->prayer_offset->value : $event->prayer_offset,
        ]);

        $groupedTerms = $event->classifications->groupBy('taxonomy_code');

        return array_replace($this->defaultsForCreate(), [
            'status' => (string) $event->status,
            'title' => $event->title,
            'description' => $event->description,
            'event_date' => $timeFields['event_date'] ?? null,
            'prayer_time' => $timeFields['prayer_time'] ?? EventPrayerTime::LainWaktu->value,
            'custom_time' => $timeFields['custom_time'] ?? null,
            'end_time' => $timeFields['end_time'] ?? null,
            'end_date' => $timeFields['end_date'] ?? null,
            'timezone' => $event->timezone,
            'event_category_ids' => $event->classifications
                ->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE)
                ->pluck('event_term_id')->filter()->values()->all(),
            'gender' => $this->normalizeEnumValue($event->gender, EventGenderRestriction::class, EventGenderRestriction::All->value),
            'age_group' => $this->normalizeEnumValues($event->age_group, EventAgeGroup::class),
            'children_allowed' => (bool) $event->children_allowed,
            'is_muslim_only' => (bool) $event->is_muslim_only,
            'event_format' => $this->normalizeEnumValue($event->delivery_mode, EventFormat::class, EventFormat::Physical->value),
            'visibility' => $this->normalizeEnumValue($event->visibility, EventVisibility::class, EventVisibility::Public->value),
            'event_url' => $event->event_url,
            'live_url' => $event->live_url,
            'recording_url' => $event->recording_url,
            'primary_organizer_id' => $event->primaryOrganizerInvolvement?->involveable_id,
            'institution_id' => $event->institution_id,
            'venue_id' => $event->default_venue_id,
            'space_ids' => $event->locations()
                ->whereNull('event_occurrence_id')
                ->whereNull('event_session_id')
                ->orderBy('sort_order')
                ->get()
                ->pluck('venue_space_id')
                ->filter()
                ->map(strval(...))
                ->values()
                ->all(),
            'languages' => $event->languages->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'domain_tags' => $groupedTerms->get('domain', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'discipline_tags' => $groupedTerms->get('discipline', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'source_tags' => $groupedTerms->get('source', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'issue_tags' => $groupedTerms->get('issue', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'references' => $event->references->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'series' => $event->series->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'speakers' => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->pluck('involveable_id')
                ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
                ->values()
                ->all(),
            'other_key_people' => $event->keyPeople
                ->where('role_code', '!=', EventKeyPersonRole::Speaker->value)
                ->map(fn ($keyPerson): array => [
                    'role_code' => (string) $keyPerson->role_code,
                    'involveable_type' => $keyPerson->involveable_type,
                    'involveable_id' => $keyPerson->involveable_id,
                    'display_name' => $keyPerson->display_name,
                    'visibility' => $keyPerson->visibility ?? 'public',
                    'notes' => $keyPerson->notes,
                ])
                ->values()
                ->all(),
            'registration_required' => (bool) $event->accessPolicy?->registration_required,
            'registration_mode' => $event->resolvedRegistrationMode()->value,
            'is_featured' => (bool) $event->is_featured,
            'clear_cover' => false,
            'clear_poster' => false,
            'clear_gallery' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Event $event = null): Event
    {
        $creating = ! $event instanceof Event;
        $event ??= new Event;

        $state = $creating
            ? array_replace($this->defaultsForCreate(), $data)
            : array_replace($this->formStateForRecord($event), $data);

        if (! $creating && array_key_exists('event_date', $data) && ! array_key_exists('end_date', $data)) {
            $state['end_date'] = null;
        }

        $this->validateState($state);

        $persistence = AdminEventTimeMapper::normalizeForPersistence($state);
        [$institutionId, $venueId, $spaceIds] = $this->resolveLocationState($state);
        $requestedStatus = $this->normalizeEventStatus(
            $state['status'] ?? ($creating ? null : (string) $event->status),
            $creating ? 'draft' : (string) $event->status,
        );

        $schedule = [
            'starts_at' => $persistence['starts_at'] ?? null,
            'ends_at' => $persistence['ends_at'] ?? null,
            'timezone' => $this->normalizeRequiredString($state['timezone'] ?? $event->timezone, 'Asia/Kuala_Lumpur'),
            'timing_mode' => $persistence['timing_mode'] ?? null,
            'prayer_reference' => $persistence['prayer_reference'] ?? null,
            'prayer_offset' => $persistence['prayer_offset'] ?? null,
            'prayer_display_text' => $persistence['prayer_display_text'] ?? null,
        ];
        $scheduleKind = ScheduleKind::tryFrom((string) ($state['schedule_kind'] ?? $event->schedule_kind)) ?? ScheduleKind::Single;

        $attributes = [
            'title' => $this->normalizeRequiredString($state['title'] ?? $event->title, 'Event'),
            'description' => array_key_exists('description', $state) ? $state['description'] : $event->description,
            'timezone' => $schedule['timezone'],
            'gender' => $this->normalizeEnumValue(
                $state['gender'] ?? $event->gender,
                EventGenderRestriction::class,
                EventGenderRestriction::All->value,
            ),
            'age_group' => $this->normalizeEnumValues(
                $state['age_group'] ?? $event->age_group,
                EventAgeGroup::class,
                [EventAgeGroup::AllAges->value],
            ),
            'children_allowed' => array_key_exists('children_allowed', $state)
                ? (bool) $state['children_allowed']
                : (bool) $event->children_allowed,
            'is_muslim_only' => array_key_exists('is_muslim_only', $state)
                ? (bool) $state['is_muslim_only']
                : (bool) $event->is_muslim_only,
            'delivery_mode' => $this->normalizeEnumValue(
                $state['event_format'] ?? $event->delivery_mode,
                EventFormat::class,
                EventFormat::Physical->value,
            ),
            'visibility' => $this->normalizeEnumValue(
                $state['visibility'] ?? $event->visibility,
                EventVisibility::class,
                EventVisibility::Public->value,
            ),
            'event_url' => $this->normalizeOptionalString($state['event_url'] ?? $event->event_url),
            'live_url' => $this->normalizeOptionalString($state['live_url'] ?? $event->live_url),
            'recording_url' => $this->normalizeOptionalString($state['recording_url'] ?? $event->recording_url),
            'institution_id' => $institutionId,
            'default_venue_id' => $venueId,
            'is_featured' => array_key_exists('is_featured', $state) ? (bool) $state['is_featured'] : (bool) $event->is_featured,
            'status' => $creating ? 'draft' : (string) $event->status,
            'published_at' => $creating ? null : $event->published_at,
        ];

        $attributes['slug'] = $this->generateSlug($attributes, $state, $event, $creating, $schedule['starts_at']);

        if ($creating) {
            $event = Event::query()->create($attributes);
        } else {
            $event->fill($attributes);
            $event->save();
        }

        $event->syncLocation($venueId, $spaceIds);

        app(SyncEventScheduleAction::class)->execute($event, $scheduleKind,
            startsAt: $schedule['starts_at'],
            endsAt: $schedule['ends_at'],
            timezone: $schedule['timezone'],
            timingMode: isset($schedule['timing_mode']) ? TimingMode::tryFrom($schedule['timing_mode']) : null,
            prayerReference: $schedule['prayer_reference'],
            prayerOffset: $schedule['prayer_offset'] !== null
                ? PrayerOffset::tryFrom((string) $schedule['prayer_offset'])?->minutes()
                : null,
            prayerDisplayText: $schedule['prayer_display_text'],
        );

        $organizerId = $this->normalizeOptionalString($state['primary_organizer_id'] ?? $event->primaryOrganizerInvolvement?->involveable_id);
        if ($organizerId) {
            $organizer = OrganizerResolver::find($organizerId);
            $event->setPrimaryOrganizer($organizer);
        } else {
            $event->setPrimaryOrganizer(null);
        }

        $this->syncReferences($event, $state);
        $this->syncSeries($event, $state);
        $this->syncEventResourceRelationsAction->handle(
            $event,
            $state,
            lockRegistrationMode: ! $creating,
            syncKeyPeople: true,
        );
        $this->syncMedia($event, $data);
        $this->syncSeatMaps($event, $state);

        if ($creating) {
            if ($requestedStatus === 'pending') {
                $this->moderationService->submitForModeration($event);
            }

            if ($requestedStatus === 'approved') {
                $this->moderationService->submitForModeration($event);
                $this->moderationService->approve($event, $actor);
            }
        }

        return $event->fresh([
            'accessPolicy',
            'references',
            'series',
            'classifications',
            'keyPeople',
            'languages',
            'media',
            'institution',
            'venue',
            'primaryLocation.venueSpace',
        ]) ?? $event;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: ?string, 1: ?string, 2: list<string>}
     */
    private function resolveLocationState(array $state): array
    {
        $institutionId = $this->normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = $this->normalizeOptionalString($state['venue_id'] ?? null);
        $spaceIds = $this->normalizeStringArray($state['space_ids'] ?? []);

        if ($venueId !== null) {
            return [null, $venueId, $spaceIds];
        }

        if ($institutionId === null) {
            return [null, null, []];
        }

        return [$institutionId, null, $spaceIds];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateState(array $state): void
    {
        $errors = [];
        $primaryOrganizerId = $this->normalizeOptionalString($state['primary_organizer_id'] ?? null);
        $institutionId = $this->normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = $this->normalizeOptionalString($state['venue_id'] ?? null);
        $spaceIds = $this->normalizeStringArray($state['space_ids'] ?? []);
        $speakerIds = $this->normalizeStringArray($state['speakers'] ?? []);

        if ($primaryOrganizerId === null) {
            $errors['primary_organizer_id'][] = __('Penganjur utama diperlukan.');
        } elseif (
            ! Institution::query()->whereKey($primaryOrganizerId)->exists()
            && ! Person::query()->whereKey($primaryOrganizerId)->exists()
        ) {
            $errors['primary_organizer_id'][] = __('Penganjur utama yang dipilih tidak sah.');
        }

        if ($institutionId !== null && $venueId !== null) {
            $message = __('Pilih institusi atau venue, bukan kedua-duanya sekali.');
            $errors['institution_id'][] = $message;
            $errors['venue_id'][] = $message;
        }

        if ($spaceIds !== [] && $institutionId === null && $venueId === null) {
            $errors['space_ids'][] = __('Ruang memerlukan institusi atau venue.');
        }

        if ($spaceIds !== [] && $institutionId !== null) {
            foreach ($spaceIds as $sid) {
                $space = Space::query()->find($sid);

                if ($space instanceof Space) {
                    $linkedInstitutionsExist = $space->institutions()->exists();
                    $isLinkedToInstitution = $space->institutions()
                        ->where('institutions.id', $institutionId)
                        ->exists();

                    if ($linkedInstitutionsExist && ! $isLinkedToInstitution) {
                        $errors['space_ids'][] = __('Ruang yang dipilih tidak tersedia untuk institusi ini.');
                    }
                }
            }
        }

        if ($spaceIds !== [] && $venueId !== null) {
            $space = Space::query()->find($spaceIds[0]);

            if ($space instanceof Space && $space->venue_id !== null && (string) $space->venue_id !== $venueId) {
                $errors['space_ids'][] = __('Ruang yang dipilih tidak tersedia untuk venue ini.');
            }
        }

        if ($this->requiresSpeakers($state['event_category_ids'] ?? []) && $speakerIds === []) {
            $errors['speakers'][] = __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $attributes
     */
    private function generateSlug(array $attributes, array $state, Event $event, bool $creating, ?string $startsAt = null): string
    {
        $primaryOrganizerId = $this->normalizeOptionalString($state['primary_organizer_id'] ?? null);
        $primaryOrganizer = OrganizerResolver::find($primaryOrganizerId);

        $speakerSlugSegments = $this->generateEventSlugAction->speakerSlugSegmentsForState(
            $this->normalizeStringArray($state['speakers'] ?? []),
            $primaryOrganizer,
        );

        return $this->generateEventSlugAction->handle(
            (string) $attributes['title'],
            $state['event_date'] ?? $startsAt ?? null,
            is_string($attributes['timezone']) ? $attributes['timezone'] : null,
            $creating ? null : (string) $event->getKey(),
            $speakerSlugSegments,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function syncReferences(Event $event, array $state): void
    {
        if (! array_key_exists('references', $state)) {
            return;
        }

        $referenceIds = $this->normalizeStringArray($state['references'] ?? []);

        $event->auditSync('references', $referenceIds, true, ['references.id', 'references.title']);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function syncSeries(Event $event, array $state): void
    {
        if (! array_key_exists('series', $state)) {
            return;
        }

        $seriesIds = $this->normalizeStringArray($state['series'] ?? []);
        $series = new Series;

        $event->auditSync('series', $seriesIds, true, [$series->qualifyColumn('id'), $series->qualifyColumn('title')]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Event $event, array $data): void
    {
        if ($this->shouldClearMediaCollection($data['clear_cover'] ?? false)) {
            $this->mediaSyncService->clearCollection($event, 'cover');
        }

        if ($this->shouldClearMediaCollection($data['clear_poster'] ?? false)) {
            $this->mediaSyncService->clearCollection($event, 'poster');
        }

        if ($this->shouldClearMediaCollection($data['clear_gallery'] ?? false)) {
            $this->mediaSyncService->clearCollection($event, 'gallery');
        }

        $cover = $data['cover'] ?? null;
        $poster = $data['poster'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $event,
            $cover instanceof UploadedFile ? $cover : null,
            'cover',
        );
        $this->mediaSyncService->syncSingle(
            $event,
            $poster instanceof UploadedFile ? $poster : null,
            'poster',
        );
        $this->mediaSyncService->syncMultiple(
            $event,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function syncSeatMaps(Event $event, array $state): void
    {
        if (! array_key_exists('seat_map_ids', $state)) {
            return;
        }

        $seatMapIds = $this->normalizeStringArray($state['seat_map_ids'] ?? []);

        $event->seatMaps()->whereNotIn('id', $seatMapIds)->delete();

        SeatMap::query()->whereIn('id', $seatMapIds)->update([
            'seatable_type' => $event->getMorphClass(),
            'seatable_id' => $event->getKey(),
        ]);
    }

    private function shouldClearMediaCollection(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    private function requiresSpeakers(mixed $categoryIds): bool
    {
        return app(EventCategoryPolicyResolver::class)->requiresSpeaker(
            app(EventCategoryCatalog::class)->validateTermIds(is_array($categoryIds) ? $categoryIds : [$categoryIds]),
        );
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        return $this->normalizeOptionalString($value) ?? $fallback;
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     */
    private function normalizeEnumValue(mixed $value, string $enumClass, string $default): string
    {
        if ($value instanceof $enumClass) {
            return (string) $value->value;
        }

        if (is_string($value) && $enumClass::tryFrom($value) instanceof BackedEnum) {
            return $value;
        }

        return $default;
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @param  list<string>  $default
     * @return list<string>
     */
    private function normalizeEnumValues(mixed $values, string $enumClass, array $default = []): array
    {
        if ($values instanceof Collection) {
            $items = $values->all();
        } elseif (is_array($values)) {
            $items = $values;
        } elseif ($values instanceof \Traversable) {
            $items = iterator_to_array($values);
        } else {
            return $default;
        }

        $normalized = Collection::make($items)
            ->map(function (mixed $value) use ($enumClass): ?string {
                if ($value instanceof $enumClass) {
                    return (string) $value->value;
                }

                return is_string($value) && $enumClass::tryFrom($value) instanceof BackedEnum
                    ? $value
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : $default;
    }

    /**
     * @param  iterable<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringArray(iterable $values): array
    {
        return Collection::make($values)
            ->map(function (mixed $value): ?string {
                if (! is_scalar($value)) {
                    return null;
                }

                $trimmed = trim((string) $value);

                return $trimmed !== '' ? $trimmed : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeEventStatus(mixed $value, string $fallback): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $normalized = trim((string) $value);

        return in_array($normalized, ['draft', 'pending', 'approved'], true)
            ? $normalized
            : $fallback;
    }
}
