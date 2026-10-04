<?php

namespace App\Actions\Events;

use AIArmada\Addressing\Models\Address;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Seating\Models\SeatMap;
use App\Actions\Prayer\ResolvePrayerStartClockAction;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Contracts\SpaceEligibilityResolver;
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
use App\Models\User;
use App\Services\ModerationService;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Events\OrganizerResolver;
use App\Support\Media\ModelMediaSyncService;
use App\Support\Prayer\PrayerLocation;
use App\Support\Prayer\PrayerTargetSelector;
use App\Support\Submission\SubmissionTimingPolicy;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon as IlluminateCarbon;
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
        private SpaceEligibilityResolver $spaceEligibilityResolver,
        private ResolvePrayerStartClockAction $resolveStartClock,
        private SubmissionTimingPolicy $timing,
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
            'submission_country_id' => null,
            'persons' => [],
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
        $event->loadMissing([
            'references.parentReference.parentReference',
            'series:id,title',
            'classifications',
            'keyPeople.person',
            // Full accessor columns: the languages attribute override reads
            // occurrence/session scope plus the code off this same instance.
            'languages:id,event_id,event_occurrence_id,event_session_id,language_code',
            'accessPolicy',
            'primaryLocation.venueSpace',
            'primaryOrganizerInvolvement',
            'locations',
            'audiences',
            'audienceProfiles',
            'links',
            'timeExpressions',
        ]);

        $prayerExpression = $event->timeExpressions
            ->first(fn ($expression): bool => $expression->anchor_type === 'prayer'
                && $expression->event_occurrence_id === null
                && $expression->event_session_id === null);

        $prayerDate = $prayerExpression?->metadata['prayer']['prayer_date'] ?? null;
        $prayerCountry = $prayerExpression?->metadata['prayer']['country'] ?? null;

        $timeFields = AdminEventTimeMapper::injectFormTimeFields([
            'starts_at' => $event->starts_at?->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),
            'timezone' => $event->timezone,
            'prayer_date' => is_string($prayerDate) ? $prayerDate : null,
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
            'submission_country_id' => is_string($prayerCountry) ? $this->timing->resolveSubmissionCountryId($prayerCountry) : null,
            'institution_id' => $event->institution_id,
            'venue_id' => $event->default_venue_id,
            'space_ids' => $event->locations
                ->whereNull('event_occurrence_id')
                ->whereNull('event_session_id')
                ->sortBy('sort_order')
                ->pluck('venue_space_id')
                ->filter()
                ->map(strval(...))
                ->values()
                ->all(),
            'languages' => $event->languages->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'domain_tags' => $groupedTerms->get('domain', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'discipline_tags' => $groupedTerms->get('discipline', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'source_tags' => $groupedTerms->get('source', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'issue_tags' => $groupedTerms->get('issue', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'references' => $event->references->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'series' => $event->series->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'persons' => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->pluck('involveable_id')
                ->filter(fn (mixed $personId): bool => is_string($personId) && $personId !== '')
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

        $recordState = $creating ? [] : $this->formStateForRecord($event);
        $state = $creating
            ? array_replace($this->defaultsForCreate(), $data)
            : array_replace($recordState, $data);

        if (! $creating && array_key_exists('event_date', $data) && ! array_key_exists('end_date', $data)) {
            $state['end_date'] = null;
        }

        $this->validateState($state);

        [$institutionId, $venueId, $spaceIds] = $this->resolveLocationState($state);

        // Prayer targets are independent of the persisted physical
        // location: online saves anchor to the organizer institution
        // through the same selector frontend submit/preview use.
        $organizerId = $this->normalizeOptionalString($state['primary_organizer_id'] ?? $event->primaryOrganizerInvolvement?->involveable_id);
        $primaryOrganizer = OrganizerResolver::find($organizerId);
        [$prayerInstitutionId, $prayerVenueId] = PrayerTargetSelector::forSave(
            $state['event_format'] ?? EventFormat::Physical->value,
            $primaryOrganizer,
            $institutionId,
            $venueId,
        );

        // Resolved once: the address feeds geo inputs and the effective
        // country feeds both resolution and fallback persistence.
        // Explicit country input is a deliberate override; an unchanged
        // inherited id is provenance, not an instruction.
        $prayerAddress = PrayerLocation::forTargets($prayerVenueId, $prayerInstitutionId);
        $recordCountryId = $creating ? null : $this->normalizeOptionalString($recordState['submission_country_id'] ?? null);
        $stateCountryId = $this->normalizeOptionalString($state['submission_country_id'] ?? null);
        $explicitCountry = $creating
            ? array_key_exists('submission_country_id', $data)
            : $stateCountryId !== $recordCountryId;
        $explicitCountryIso = $explicitCountry
            ? $this->timing->countryIso2ForId($state['submission_country_id'] ?? null)
            : null;
        $targetsChanged = $creating || $this->prayerTargetsChanged($state, $recordState);
        $prayerCountryIso = $this->prayerCountryIso(
            $prayerAddress,
            $explicitCountryIso,
            $targetsChanged,
            $creating ? null : $this->pinnedPrayerCountry($event),
        );

        $timezone = $this->normalizeRequiredString($state['timezone'] ?? $event->timezone, 'Asia/Kuala_Lumpur');

        // Unchanged timing is determined BEFORE provider resolution and
        // normalization: during an outage the fallback start can land after
        // the persisted end, and the end-time comparison below would reject
        // a title-only edit before persisted timing could be restored.
        // Rebuilding the resolved clock from the persisted start also skips
        // a pointless provider resolution on unrelated edits.
        $preserveTiming = ! $creating
            && $event->starts_at !== null
            && $this->timingInputsUnchanged($state, $recordState, $timezone);

        // Shared calendar eligibility (Friday Jumaat, Ramadan Tarawih)
        // applies on every save: persisted timing must satisfy the
        // current rule even when the edit touches nothing else.
        $this->assertCalendarEligibility($state, $timezone, $prayerCountryIso);

        $resolvedStart = $preserveTiming
            ? $this->persistedStartClock($event, $timezone)
            : $this->resolveProviderStartClock($state, $prayerAddress, $prayerCountryIso);

        $persistence = AdminEventTimeMapper::normalizeForPersistence(array_merge($state, [
            'resolved_start_clock' => $resolvedStart['clock'] ?? null,
            'resolved_start_date' => $resolvedStart['date'] ?? null,
            'resolved_start_instant' => $resolvedStart['starts_at'] ?? null,
            'prayer_source' => $resolvedStart['source'] ?? null,
            'prayer_fetched_at' => $resolvedStart['fetched_at'] ?? null,
            'prayer_zone' => $resolvedStart['zone'] ?? null,
            'prayer_lat' => $resolvedStart['lat'] ?? null,
            'prayer_lng' => $resolvedStart['lng'] ?? null,
        ]));
        $requestedStatus = $this->normalizeEventStatus(
            $state['status'] ?? ($creating ? null : (string) $event->status),
            $creating ? 'draft' : (string) $event->status,
        );

        $schedule = [
            'starts_at' => $persistence['starts_at'] ?? null,
            'ends_at' => $persistence['ends_at'] ?? null,
            'timezone' => $timezone,
            'timing_mode' => $persistence['timing_mode'] ?? null,
            'prayer_reference' => $persistence['prayer_reference'] ?? null,
            'prayer_offset' => $persistence['prayer_offset'] ?? null,
            'prayer_display_text' => $persistence['prayer_display_text'] ?? null,
            'prayer_source' => $persistence['prayer_source'] ?? null,
            'prayer_fetched_at' => $persistence['prayer_fetched_at'] ?? null,
            'prayer_zone' => $persistence['prayer_zone'] ?? null,
            'prayer_lat' => $persistence['prayer_lat'] ?? null,
            'prayer_lng' => $persistence['prayer_lng'] ?? null,
            'prayer_country' => $resolvedStart['country'] ?? $prayerCountryIso,
        ];

        // Unrelated edits (title, description, ...) must never move the
        // event's start: a title-only save during a provider outage would
        // otherwise overwrite a resolved 19:07 with the 20:00 estimate.
        // The decision was taken before normalization so the end-time
        // comparison above already ran against the persisted start.
        if ($preserveTiming) {
            $schedule['starts_at'] = $event->starts_at;
            $schedule['ends_at'] = $event->ends_at;

            // Normalization replaces the stored offset/display with preset
            // defaults; unchanged timing keeps the expression's exact
            // anchor, offset, and label instead.
            $schedule['prayer_reference'] = $event->prayer_reference;
            $schedule['prayer_offset'] = $event->prayer_offset;
            $schedule['prayer_display_text'] = $event->prayer_display_text;

            $prayerMeta = $event->timeExpressions
                ->first(fn ($expression): bool => $expression->anchor_type === 'prayer')
                ?->metadata['prayer'] ?? null;

            if (is_array($prayerMeta)) {
                $schedule['prayer_source'] = $prayerMeta['source'] ?? $schedule['prayer_source'];
                $schedule['prayer_fetched_at'] = $prayerMeta['fetched_at'] ?? $schedule['prayer_fetched_at'];
                $schedule['prayer_zone'] = $prayerMeta['zone'] ?? $schedule['prayer_zone'];
                $schedule['prayer_lat'] = $prayerMeta['lat'] ?? $schedule['prayer_lat'];
                $schedule['prayer_lng'] = $prayerMeta['lng'] ?? $schedule['prayer_lng'];
                $schedule['prayer_country'] = $prayerMeta['country'] ?? $schedule['prayer_country'];
            }
        }
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
            prayerSource: $schedule['prayer_source'],
            prayerFetchedAt: $schedule['prayer_fetched_at'],
            prayerZone: $schedule['prayer_zone'] ?? null,
            prayerDate: AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone),
            prayerLat: isset($schedule['prayer_lat']) && is_numeric($schedule['prayer_lat']) ? (float) $schedule['prayer_lat'] : null,
            prayerLng: isset($schedule['prayer_lng']) && is_numeric($schedule['prayer_lng']) ? (float) $schedule['prayer_lng'] : null,
            prayerVenueId: $prayerVenueId,
            prayerInstitutionId: $prayerInstitutionId,
            prayerCountry: $schedule['prayer_country'],
        );

        $event->setPrimaryOrganizer($organizerId !== null ? $primaryOrganizer : null);

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
    /**
     * @param  array<string, mixed>  $state
     * @return array{clock: string, date: string, source: string, fetched_at: string, zone: string|null}|null
     */
    /**
     * True when every timing-relevant input matches the record's current
     * form state. Dates normalize through the shared admin parser so a
     * localized d/m/Y input compares equal to its stored Y-m-d.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $recordState
     */
    private function timingInputsUnchanged(array $state, array $recordState, string $timezone): bool
    {
        $left = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);
        $right = AdminEventTimeMapper::normalizeEventDateString($recordState['event_date'] ?? null, $timezone);

        if ($left !== $right) {
            return false;
        }

        foreach (['prayer_time', 'custom_time', 'end_time', 'end_date', 'timezone', 'institution_id', 'venue_id', 'event_format', 'primary_organizer_id', 'submission_country_id'] as $key) {
            if ($this->normalizeTimingScalar($state[$key] ?? null) !== $this->normalizeTimingScalar($recordState[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeTimingScalar(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Rebuilds a resolved clock/date pair from the persisted start so the
     * end-time comparison in normalization runs against real timing when
     * inputs are unchanged. Provenance is restored from the persisted
     * expression after normalization, never from a fresh resolution.
     *
     * @return array{clock: string, date: string, starts_at: string}
     */
    private function persistedStartClock(Event $event, string $timezone): array
    {
        $startsAt = Carbon::parse($event->starts_at)->setTimezone($timezone);

        return [
            'clock' => $startsAt->format('H:i'),
            'date' => $startsAt->toDateString(),
            'starts_at' => Carbon::parse($event->starts_at)->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{clock: string, date: string, starts_at: string, source: string, fetched_at: string, zone: string|null}|null
     */
    private function resolveProviderStartClock(array $state, ?Address $address, string $countryIso): ?array
    {
        $prayerTime = EventPrayerTime::tryFrom((string) ($state['prayer_time'] ?? ''));
        $timezone = (string) ($state['timezone'] ?? 'Asia/Kuala_Lumpur');
        $eventDate = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);

        if (! $prayerTime instanceof EventPrayerTime || $eventDate === null) {
            return null;
        }

        $location = PrayerLocation::fromAddress($address);

        return $this->resolveStartClock->handle(
            countryCode: $countryIso,
            date: $eventDate,
            timezone: $timezone,
            prayerTime: $prayerTime,
            latitude: $location['latitude'],
            longitude: $location['longitude'],
            stateCode: $location['stateCode'],
            districtCandidates: $location['districtCandidates'],
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertCalendarEligibility(array $state, string $timezone, string $countryIso): void
    {
        $prayerTime = EventPrayerTime::tryFrom((string) ($state['prayer_time'] ?? ''));

        if (! $prayerTime instanceof EventPrayerTime) {
            return;
        }

        $eventDate = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);

        if ($eventDate === null) {
            return;
        }

        $this->timing->assertPrayerDateIsAllowed(
            $prayerTime,
            IlluminateCarbon::parse($eventDate, $timezone),
            $timezone,
            '',
            $countryIso,
        );
    }

    /**
     * Effective prayer country. Explicit input wins but must agree with
     * the effective address, mirroring frontend submission validation.
     * Without explicit input, changed targets follow the new address;
     * unchanged or address-less targets retain the pinned country.
     */
    private function prayerCountryIso(?Address $address, ?string $explicitCountryIso, bool $targetsChanged, ?string $pinnedCountryIso): string
    {
        $addressCountry = PrayerLocation::fromAddress($address)['countryCode'] ?? null;
        $addressCountry = is_string($addressCountry) && trim($addressCountry) !== '' ? $addressCountry : null;

        if ($explicitCountryIso !== null) {
            if ($addressCountry !== null && strtoupper($addressCountry) !== strtoupper($explicitCountryIso)) {
                throw ValidationException::withMessages([
                    'submission_country_id' => __('Negara yang dipilih tidak sepadan dengan lokasi yang dipilih.'),
                ]);
            }

            return $explicitCountryIso;
        }

        if ($targetsChanged) {
            return $addressCountry ?? $pinnedCountryIso ?? 'MY';
        }

        return $pinnedCountryIso ?? $addressCountry ?? 'MY';
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $recordState
     */
    private function prayerTargetsChanged(array $state, array $recordState): bool
    {
        foreach (['event_format', 'primary_organizer_id', 'institution_id', 'venue_id'] as $key) {
            if ($this->normalizeTimingScalar($state[$key] ?? null) !== $this->normalizeTimingScalar($recordState[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function pinnedPrayerCountry(Event $event): ?string
    {
        $event->loadMissing('timeExpressions');

        $country = $event->timeExpressions
            ->first(fn ($expression): bool => $expression->anchor_type === 'prayer'
                && $expression->event_occurrence_id === null
                && $expression->event_session_id === null)
            ?->metadata['prayer']['country'] ?? null;

        return is_string($country) && trim($country) !== '' ? $country : null;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: string|null, 1: string|null, 2: list<string>}
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
        $personIds = $this->normalizeStringArray($state['persons'] ?? []);

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

        $submissionCountryId = $this->normalizeOptionalString($state['submission_country_id'] ?? null);

        if ($submissionCountryId !== null && $this->timing->resolveSubmissionCountryId($submissionCountryId) === null) {
            $errors['submission_country_id'][] = __('The selected country is invalid.');
        }

        if ($spaceIds !== [] && $institutionId === null && $venueId === null) {
            $errors['space_ids'][] = __('Ruang memerlukan institusi atau venue.');
        }

        if ($spaceIds !== [] && $institutionId !== null) {
            try {
                $this->spaceEligibilityResolver->validateInstitutionSelection($institutionId, $spaceIds);
            } catch (ValidationException $exception) {
                $errors = array_merge_recursive($errors, $exception->errors());
            }
        }

        if ($spaceIds !== [] && $venueId !== null) {
            try {
                $this->spaceEligibilityResolver->validateVenueSelection($venueId, $spaceIds);
            } catch (ValidationException $exception) {
                $errors = array_merge_recursive($errors, $exception->errors());
            }
        }

        if ($this->requiresPersons($state['event_category_ids'] ?? []) && $personIds === []) {
            $errors['persons'][] = __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.');
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

        $personSlugSegments = $this->generateEventSlugAction->personSlugSegmentsForState(
            $this->normalizeStringArray($state['persons'] ?? []),
            $primaryOrganizer,
        );

        return $this->generateEventSlugAction->handle(
            (string) $attributes['title'],
            $state['event_date'] ?? $startsAt ?? null,
            is_string($attributes['timezone']) ? $attributes['timezone'] : null,
            $creating ? null : (string) $event->getKey(),
            $personSlugSegments,
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

    private function requiresPersons(mixed $categoryIds): bool
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
