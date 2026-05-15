<?php

namespace App\Actions\Events;

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\User;
use App\Services\ModerationService;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Media\ModelMediaSyncService;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'children_allowed' => false,
            'is_muslim_only' => false,
            'references' => [],
            'series' => [],
            'languages' => [],
            'domain_tags' => [],
            'discipline_tags' => [],
            'source_tags' => [],
            'issue_tags' => [],
            'speakers' => [],
            'other_key_people' => [],
            'registration_required' => false,
            'registration_mode' => RegistrationMode::Event->value,
            'is_priority' => false,
            'is_featured' => false,
            'is_active' => true,
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
        $event->loadMissing(['references:id,title', 'series:id,title', 'tags:id,type', 'keyPeople', 'languages:id', 'settings']);

        $timeFields = AdminEventTimeMapper::injectFormTimeFields([
            'starts_at' => $event->starts_at?->toDateTimeString(),
            'ends_at' => $event->ends_at?->toDateTimeString(),
            'timezone' => $event->timezone,
            'timing_mode' => $event->timing_mode instanceof BackedEnum ? $event->timing_mode->value : $event->timing_mode,
            'prayer_reference' => $event->prayer_reference instanceof BackedEnum ? $event->prayer_reference->value : $event->prayer_reference,
            'prayer_offset' => $event->prayer_offset instanceof BackedEnum ? $event->prayer_offset->value : $event->prayer_offset,
        ]);

        $groupedTags = $event->tags->groupBy('type');

        return array_replace($this->defaultsForCreate(), [
            'status' => (string) $event->status,
            'title' => $event->title,
            'description' => $event->description,
            'event_date' => $timeFields['event_date'] ?? null,
            'prayer_time' => $timeFields['prayer_time'] ?? EventPrayerTime::LainWaktu->value,
            'custom_time' => $timeFields['custom_time'] ?? null,
            'end_time' => $timeFields['end_time'] ?? null,
            'timezone' => $event->timezone,
            'event_type' => $this->normalizeEnumValues($event->event_type, EventType::class),
            'gender' => $this->normalizeEnumValue($event->gender, EventGenderRestriction::class, EventGenderRestriction::All->value),
            'age_group' => $this->normalizeEnumValues($event->age_group, EventAgeGroup::class),
            'children_allowed' => (bool) $event->children_allowed,
            'is_muslim_only' => (bool) $event->is_muslim_only,
            'event_format' => $this->normalizeEnumValue($event->event_format, EventFormat::class, EventFormat::Physical->value),
            'visibility' => $this->normalizeEnumValue($event->visibility, EventVisibility::class, EventVisibility::Public->value),
            'event_url' => $event->event_url,
            'live_url' => $event->live_url,
            'recording_url' => $event->recording_url,
            'organizer_type' => $this->normalizeOrganizerType($event->organizer_type),
            'organizer_id' => $event->organizer_id,
            'institution_id' => $event->institution_id,
            'venue_id' => $event->venue_id,
            'space_id' => $event->space_id,
            'languages' => $event->languages->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'domain_tags' => $groupedTags->get('domain', collect())->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'discipline_tags' => $groupedTags->get('discipline', collect())->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'source_tags' => $groupedTags->get('source', collect())->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'issue_tags' => $groupedTags->get('issue', collect())->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'references' => $event->references->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'series' => $event->series->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'speakers' => $event->keyPeople
                ->where('role', EventKeyPersonRole::Speaker)
                ->pluck('speaker_id')
                ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
                ->values()
                ->all(),
            'other_key_people' => $event->keyPeople
                ->where('role', '!=', EventKeyPersonRole::Speaker)
                ->map(fn ($keyPerson): array => [
                    'role' => $keyPerson->role instanceof BackedEnum ? $keyPerson->role->value : (string) $keyPerson->role,
                    'speaker_id' => $keyPerson->speaker_id,
                    'name' => $keyPerson->name,
                    'is_public' => (bool) $keyPerson->is_public,
                    'notes' => $keyPerson->notes,
                ])
                ->values()
                ->all(),
            'registration_required' => (bool) $event->settings?->registration_required,
            'registration_mode' => $this->normalizeEnumValue(
                $event->settings?->registration_mode,
                RegistrationMode::class,
                RegistrationMode::Event->value,
            ),
            'is_priority' => (bool) $event->is_priority,
            'is_featured' => (bool) $event->is_featured,
            'is_active' => (bool) $event->is_active,
            'escalated_at' => $event->escalated_at instanceof Carbon
                ? $event->escalated_at->toDateTimeString()
                : null,
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

        $state['organizer_type'] = $this->normalizeOrganizerType($state['organizer_type'] ?? null);
        $this->validateState($state);

        $persistence = AdminEventTimeMapper::normalizeForPersistence($state);
        [$institutionId, $venueId, $spaceId] = $this->resolveLocationState($state);
        $requestedStatus = $this->normalizeEventStatus(
            $state['status'] ?? ($creating ? null : (string) $event->status),
            $creating ? 'draft' : (string) $event->status,
        );

        $attributes = [
            'title' => $this->normalizeRequiredString($state['title'] ?? $event->title, 'Event'),
            'description' => array_key_exists('description', $state) ? $state['description'] : $event->description,
            'starts_at' => $persistence['starts_at'] ?? $event->starts_at,
            'ends_at' => $persistence['ends_at'] ?? $event->ends_at,
            'timezone' => $this->normalizeRequiredString($state['timezone'] ?? $event->timezone, 'Asia/Kuala_Lumpur'),
            'timing_mode' => $persistence['timing_mode'] ?? $event->timing_mode,
            'prayer_reference' => $persistence['prayer_reference'] ?? $event->prayer_reference,
            'prayer_offset' => $persistence['prayer_offset'] ?? $event->prayer_offset,
            'prayer_display_text' => $persistence['prayer_display_text'] ?? $event->prayer_display_text,
            'event_type' => $this->normalizeEnumValues(
                $state['event_type'] ?? $event->event_type,
                EventType::class,
                [EventType::Other->value],
            ),
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
            'event_format' => $this->normalizeEnumValue(
                $state['event_format'] ?? $event->event_format,
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
            'organizer_type' => $state['organizer_type'],
            'organizer_id' => $this->normalizeOptionalString($state['organizer_id'] ?? $event->organizer_id),
            'institution_id' => $institutionId,
            'venue_id' => $venueId,
            'space_id' => $spaceId,
            'is_priority' => array_key_exists('is_priority', $state) ? (bool) $state['is_priority'] : (bool) $event->is_priority,
            'is_featured' => array_key_exists('is_featured', $state) ? (bool) $state['is_featured'] : (bool) $event->is_featured,
            'is_active' => array_key_exists('is_active', $state) ? (bool) $state['is_active'] : ($creating ? true : (bool) $event->is_active),
            'status' => $creating ? 'draft' : (string) $event->status,
            'published_at' => $creating ? null : $event->published_at,
            'escalated_at' => $this->normalizeOptionalDateTime($state['escalated_at'] ?? $event->escalated_at),
        ];

        $attributes['slug'] = $this->generateSlug($attributes, $state, $event, $creating);

        if ($creating) {
            $event = Event::query()->create($attributes);
        } else {
            $event->fill($attributes);
            $event->save();
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
            'settings',
            'references',
            'series',
            'tags',
            'keyPeople',
            'languages',
            'media',
            'institution',
            'venue',
            'space',
        ]) ?? $event;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function resolveLocationState(array $state): array
    {
        $institutionId = $this->normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = $this->normalizeOptionalString($state['venue_id'] ?? null);
        $spaceId = $this->normalizeOptionalString($state['space_id'] ?? null);

        if ($venueId !== null) {
            return [null, $venueId, null];
        }

        if ($institutionId === null) {
            return [null, null, null];
        }

        return [$institutionId, null, $spaceId];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateState(array $state): void
    {
        $errors = [];
        $organizerType = $this->normalizeOrganizerType($state['organizer_type'] ?? null);
        $organizerId = $this->normalizeOptionalString($state['organizer_id'] ?? null);
        $institutionId = $this->normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = $this->normalizeOptionalString($state['venue_id'] ?? null);
        $spaceId = $this->normalizeOptionalString($state['space_id'] ?? null);
        $speakerIds = $this->normalizeStringArray($state['speakers'] ?? []);

        if ($organizerId !== null && $organizerType === null) {
            $errors['organizer_type'][] = __('Sila pilih jenis penganjur untuk ID penganjur yang diberikan.');
        }

        if ($organizerType !== null && $organizerId === null) {
            $errors['organizer_id'][] = __('Sila pilih penganjur untuk jenis penganjur yang dipilih.');
        }

        if ($organizerType === Institution::class && $organizerId !== null && ! Institution::query()->whereKey($organizerId)->exists()) {
            $errors['organizer_id'][] = __('Penganjur institusi yang dipilih tidak wujud.');
        }

        if ($organizerType === Speaker::class && $organizerId !== null && ! Speaker::query()->whereKey($organizerId)->exists()) {
            $errors['organizer_id'][] = __('Penganjur penceramah yang dipilih tidak wujud.');
        }

        if ($institutionId !== null && $venueId !== null) {
            $message = __('Pilih institusi atau venue, bukan kedua-duanya sekali.');
            $errors['institution_id'][] = $message;
            $errors['venue_id'][] = $message;
        }

        if ($spaceId !== null && ($institutionId === null || $venueId !== null)) {
            $errors['space_id'][] = __('Ruang hanya boleh dipilih apabila institusi dipilih tanpa venue.');
        }

        if ($spaceId !== null && $institutionId !== null) {
            $space = Space::query()->find($spaceId);

            if ($space instanceof Space) {
                $linkedInstitutionsExist = $space->institutions()->exists();
                $isLinkedToInstitution = $space->institutions()
                    ->where('institutions.id', $institutionId)
                    ->exists();

                if ($linkedInstitutionsExist && ! $isLinkedToInstitution) {
                    $errors['space_id'][] = __('Ruang yang dipilih tidak tersedia untuk institusi ini.');
                }
            }
        }

        if ($this->requiresSpeakers($state['event_type'] ?? []) && $speakerIds === []) {
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
    private function generateSlug(array $attributes, array $state, Event $event, bool $creating): string
    {
        $speakerSlugSegments = $this->generateEventSlugAction->speakerSlugSegmentsForSpeakerIds(
            $this->normalizeStringArray($state['speakers'] ?? []),
        );

        if (
            $speakerSlugSegments === []
            && ($state['organizer_type'] ?? null) === Speaker::class
            && filled($state['organizer_id'] ?? null)
        ) {
            $speakerSlugSegments = $this->generateEventSlugAction->speakerSlugSegmentsForSpeakerIds([
                (string) $state['organizer_id'],
            ]);
        }

        return $this->generateEventSlugAction->handle(
            (string) $attributes['title'],
            $state['event_date'] ?? $attributes['starts_at'] ?? null,
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

        $event->auditSync('series', $seriesIds, true, ['series.id', 'series.title']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Event $event, array $data): void
    {
        if (($data['clear_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($event, 'cover');
        }

        if (($data['clear_poster'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($event, 'poster');
        }

        if (($data['clear_gallery'] ?? false) === true) {
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

    private function normalizeOrganizerType(mixed $value): ?string
    {
        return match ($value) {
            Institution::class, 'institution' => Institution::class,
            Speaker::class, 'speaker' => Speaker::class,
            default => null,
        };
    }

    private function requiresSpeakers(mixed $eventTypes): bool
    {
        foreach ($this->normalizeEnumValues($eventTypes, EventType::class) as $eventTypeValue) {
            $eventType = EventType::tryFrom($eventTypeValue);

            if ($eventType?->requiresSpeakerByDefault()) {
                return true;
            }
        }

        return false;
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

    private function normalizeOptionalDateTime(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->utc();
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value)->utc();
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
