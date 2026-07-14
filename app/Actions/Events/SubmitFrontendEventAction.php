<?php

namespace App\Actions\Events;

use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Events\Enums\RegistrationMode;
use App\Contracts\CaptchaVerifier;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventStructure;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Services\EventKeyPersonSyncService;
use App\Services\ModerationService;
use App\Services\ShareTrackingService;
use App\States\EventStatus\Pending;
use App\Support\Submission\EntitySubmissionAccess;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class SubmitFrontendEventAction
{
    use AsAction;

    public function __construct(
        private readonly EntitySubmissionAccess $entitySubmissionAccess,
        private readonly EventKeyPersonSyncService $eventKeyPersonSyncService,
        private readonly ModerationService $moderationService,
        private readonly ShareTrackingService $shareTrackingService,
        private readonly CaptchaVerifier $turnstileVerifier,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @param  (callable(Event): void)|null  $persistRelationships
     * @return array{event: Event, submission: EventSubmission, auto_approved: bool, visibility: string}
     */
    public function handle(
        array $state,
        Request $request,
        ?User $submitter = null,
        ?Event $parentEvent = null,
        ?Institution $scopedInstitution = null,
        ?callable $persistRelationships = null,
        string $validationKeyPrefix = '',
    ): array {
        $validated = $this->normalizeEnumState(
            $this->normalizeScopedInstitutionState($state, $scopedInstitution, $validationKeyPrefix),
        );
        $this->assertCaptchaIsValid($request, $validated['captcha_token'] ?? null, $validationKeyPrefix);
        $this->assertConditionalRequirements($validated, $validationKeyPrefix);
        $this->assertValidSubmissionCountryId($validated, $validationKeyPrefix);

        $ageGroups = $validated['age_group'] ?? [];

        if (
            in_array(EventAgeGroup::Children->value, $ageGroups, true)
            || in_array(EventAgeGroup::AllAges->value, $ageGroups, true)
        ) {
            $validated['children_allowed'] = true;
        }

        $this->assertSubmissionEntitiesAreAccessible($validated, $submitter, $validationKeyPrefix);

        $submissionCountryId = $this->resolveSubmissionCountryId($validated);
        $startsAt = $this->resolveStartsAt($validated);
        $timezone = $this->resolveSubmissionTimezone($validated, $submissionCountryId);

        if (
            $this->hasCommunityEventTypeSelection($validated['event_type'] ?? [])
            && (($validated['event_format'] ?? EventFormat::Physical->value) !== EventFormat::Physical->value)
        ) {
            throw ValidationException::withMessages([
                $this->validationKey('event_format', $validationKeyPrefix) => __('Jenis majlis komuniti mesti menggunakan format fizikal.'),
            ]);
        }

        $prayerTimeRaw = $validated['prayer_time'] ?? '';
        $selectedPrayer = $prayerTimeRaw instanceof EventPrayerTime
            ? $prayerTimeRaw
            : EventPrayerTime::tryFrom((string) $prayerTimeRaw);
        $eventDate = Carbon::parse((string) $validated['event_date'], $timezone)->startOfDay();

        if (
            in_array($selectedPrayer, [EventPrayerTime::SebelumJumaat, EventPrayerTime::SelepasJumaat], true)
            && ! $eventDate->isFriday()
        ) {
            throw ValidationException::withMessages([
                $this->validationKey('prayer_time', $validationKeyPrefix) => __('Pilihan waktu Jumaat hanya boleh dipilih untuk hari Jumaat.'),
            ]);
        }

        if ($selectedPrayer === EventPrayerTime::SebelumMaghrib && ! $this->isRamadhan($eventDate, $timezone)) {
            throw ValidationException::withMessages([
                $this->validationKey('prayer_time', $validationKeyPrefix) => __('Sebelum Maghrib hanya boleh dipilih semasa bulan Ramadhan.'),
            ]);
        }

        if ($selectedPrayer === EventPrayerTime::SelepasTarawih && ! $this->isRamadhan($eventDate, $timezone)) {
            throw ValidationException::withMessages([
                $this->validationKey('prayer_time', $validationKeyPrefix) => __('Selepas Tarawih hanya boleh dipilih semasa bulan Ramadhan.'),
            ]);
        }

        [$primaryOrganizer, $targetInstitutionId, $targetVenueId] = $this->resolveOrganizerAndLocation(
            $validated,
            $validationKeyPrefix,
        );

        $prayerTime = $prayerTimeRaw instanceof EventPrayerTime
            ? $prayerTimeRaw
            : EventPrayerTime::tryFrom((string) $prayerTimeRaw);
        $prayerReference = $prayerTime?->toPrayerReference();
        $prayerOffset = $prayerTime?->getDefaultOffset();
        $prayerDisplayText = $prayerTime && ! $prayerTime->isCustomTime() ? $prayerTime->getLabel() : null;

        $this->validateEndsAtAfterStartsAt($validated, $startsAt, $timezone, $validationKeyPrefix);

        if ($startsAt->lessThanOrEqualTo(Carbon::now($timezone))) {
            $errorField = $prayerTime?->isCustomTime() ? 'custom_time' : 'prayer_time';

            throw ValidationException::withMessages([
                $this->validationKey($errorField, $validationKeyPrefix) => __('Waktu majlis yang dipilih telah berlalu. Sila pilih waktu lain.'),
            ]);
        }

        $autoApproved = $scopedInstitution instanceof Institution;
        $speakerSlugSegments = app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds(
            is_array($validated['speakers'] ?? null) ? $validated['speakers'] : [],
        );

        if ($speakerSlugSegments === [] && $primaryOrganizer instanceof Speaker) {
            $speakerSlugSegments = app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds([
                (string) $primaryOrganizer->getKey(),
            ]);
        }

        $event = Event::query()->create(array_merge([
            'title' => $validated['title'],
            'slug' => app(GenerateEventSlugAction::class)->handle(
                (string) $validated['title'],
                $validated['event_date'] ?? null,
                $timezone,
                null,
                $speakerSlugSegments,
            ),
            'description' => $validated['description'] ?? null,
            'timezone' => $timezone,
            'starts_at' => $startsAt,
            'ends_at' => $this->resolveEndsAt($validated, $startsAt, $timezone),
            'institution_id' => $targetInstitutionId,
            'venue_id' => $targetVenueId,
            'space_id' => $validated['space_id'] ?? null,
            'parent_event_id' => $parentEvent?->getKey(),
            'event_structure' => $parentEvent instanceof Event ? EventStructure::ChildEvent->value : EventStructure::Standalone->value,
            'event_type' => $validated['event_type'] ?? [EventType::KuliahCeramah->value],
            'gender' => $validated['gender'] ?? EventGenderRestriction::All->value,
            'age_group' => $validated['age_group'] ?? [EventAgeGroup::AllAges->value],
            'children_allowed' => $validated['children_allowed'] ?? true,
            'is_muslim_only' => $validated['is_muslim_only'] ?? false,
            'timing_mode' => $prayerTime?->isCustomTime() ? 'absolute' : 'prayer_relative',
            'prayer_reference' => $prayerReference?->value,
            'prayer_offset' => $prayerOffset?->value,
            'prayer_display_text' => $prayerDisplayText,
            'event_format' => $validated['event_format'] ?? EventFormat::Physical->value,
            'event_url' => $validated['event_url'] ?? null,
            'live_url' => $validated['live_url'] ?? null,
            'visibility' => $validated['visibility'] ?? EventVisibility::Public->value,
            'submitter_id' => $submitter?->getKey(),
        ], $autoApproved ? ['status' => 'pending'] : []));

        $event->setPrimaryOrganizer($primaryOrganizer);

        if (! empty($validated['space_id']) && ! empty($event->institution_id)) {
            $institution = Institution::query()->find($event->institution_id);

            if (
                $institution instanceof Institution
                && ! $institution->spaces()->where('spaces.id', $validated['space_id'])->exists()
            ) {
                $institution->spaces()->attach($validated['space_id']);
            }
        }

        $this->eventKeyPersonSyncService->sync(
            $event,
            $validated['speakers'] ?? [],
            $validated['other_key_people'] ?? [],
        );

        if (! empty($validated['languages'])) {
            $event->syncLanguages($validated['languages']);
        }

        app(SyncEventClassificationsAction::class)->handle($event, $validated);

        if ($persistRelationships !== null) {
            $persistRelationships($event);
        }

        $submissionData = [
            'submitter_name' => $validated['submitter_name'] ?? $submitter?->name,
        ];
        if (isset($validated['notes'])) {
            $submissionData['notes'] = $validated['notes'];
        }

        $submission = EventSubmission::query()->create([
            'event_id' => $event->getKey(),
            'status' => 'pending',
            'submitted_at' => now(),
            'submission_data' => $submissionData,
            'submitter_type' => $submitter !== null ? User::class : null,
            'submitter_id' => $submitter?->getKey(),
        ]);

        $this->shareTrackingService->recordOutcome(
            type: DawahShareOutcomeType::EventSubmission,
            outcomeKey: 'event_submission:submission:'.$submission->getKey(),
            subject: $event,
            actor: $submitter,
            request: $request,
            metadata: [
                'submission_id' => $submission->getKey(),
                'submitted_by' => $submission->submitter_id,
            ],
        );

        if (! $submitter instanceof User) {
            $this->storeSubmitterContacts($submission, $validated);
        }

        $this->persistRegistrationSettings($event, $parentEvent);

        if ($autoApproved) {
            $this->moderationService->approve($event, null, 'Auto-approved from institution dashboard submission.');
        } else {
            $event->status->transitionTo(Pending::class);
        }

        $visibility = $event->visibility;

        return [
            'event' => $event->fresh() ?? $event,
            'submission' => $submission->fresh() ?? $submission,
            'auto_approved' => $autoApproved,
            'visibility' => $visibility instanceof EventVisibility ? $visibility->value : (string) $visibility,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeScopedInstitutionState(array $validated, ?Institution $scopedInstitution, string $validationKeyPrefix): array
    {
        if (! $scopedInstitution instanceof Institution) {
            return $validated;
        }

        $validated['primary_organizer_id'] = $scopedInstitution->getKey();
        $validated['primary_organizer_kind'] = 'institution';
        $validated['primary_organizer_institution_id'] = $scopedInstitution->getKey();
        $validated['location_same_as_institution'] = (bool) ($validated['location_same_as_institution'] ?? true);

        if ($this->normalizeEnumValue($validated['event_format'] ?? null, EventFormat::Physical->value) === EventFormat::Online->value) {
            $validated['location_type'] = 'institution';
            $validated['location_institution_id'] = $scopedInstitution->getKey();
            $validated['location_venue_id'] = null;

            return $validated;
        }

        if ($validated['location_same_as_institution']) {
            $validated['location_type'] = 'institution';
            $validated['location_institution_id'] = $scopedInstitution->getKey();
            $validated['location_venue_id'] = null;

            return $validated;
        }

        $validated['location_type'] = 'venue';
        $validated['location_institution_id'] = null;

        if (! filled($validated['location_venue_id'] ?? null)) {
            throw ValidationException::withMessages([
                $this->validationKey('location_venue_id', $validationKeyPrefix) => __('Sila pilih lokasi untuk majlis ini.'),
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertConditionalRequirements(array $validated, string $validationKeyPrefix): void
    {
        $eventFormat = $this->normalizeEnumValue($validated['event_format'] ?? null, EventFormat::Physical->value);
        $organizerType = $this->resolvePrimaryOrganizerKind($validated['primary_organizer_id'] ?? null);
        $sameAsInstitution = (bool) ($validated['location_same_as_institution'] ?? true);
        $locationType = (string) ($validated['location_type'] ?? '');

        if ($organizerType === null) {
            throw ValidationException::withMessages([
                $this->validationKey('primary_organizer_id', $validationKeyPrefix) => __('Sila pilih penganjur utama.'),
            ]);
        }

        if ($eventFormat === EventFormat::Online->value) {
            return;
        }

        $requiresLocationChoice = $organizerType === 'speaker' || ! $sameAsInstitution;

        if (! $requiresLocationChoice) {
            return;
        }

        if (! in_array($locationType, ['institution', 'venue'], true)) {
            throw ValidationException::withMessages([
                $this->validationKey('location_type', $validationKeyPrefix) => __('Sila pilih jenis lokasi untuk majlis ini.'),
            ]);
        }

        if ($locationType === 'institution' && ! filled($validated['location_institution_id'] ?? null)) {
            throw ValidationException::withMessages([
                $this->validationKey('location_institution_id', $validationKeyPrefix) => __('Sila pilih institusi lokasi untuk majlis ini.'),
            ]);
        }

        if ($locationType === 'venue' && ! filled($validated['location_venue_id'] ?? null)) {
            throw ValidationException::withMessages([
                $this->validationKey('location_venue_id', $validationKeyPrefix) => __('Sila pilih lokasi untuk majlis ini.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertSubmissionEntitiesAreAccessible(array $validated, ?User $submitter, string $validationKeyPrefix = ''): void
    {
        $organizerType = $this->resolvePrimaryOrganizerKind($validated['primary_organizer_id'] ?? null);
        $primaryOrganizerId = (string) ($validated['primary_organizer_id'] ?? '');
        $locationInstitutionId = (string) ($validated['location_institution_id'] ?? '');

        if ($organizerType === 'institution' && $primaryOrganizerId !== '' && ! $this->entitySubmissionAccess->canUseInstitution($submitter, $primaryOrganizerId)) {
            throw ValidationException::withMessages([
                $this->validationKey('primary_organizer_id', $validationKeyPrefix) => __('Anda tidak dibenarkan memilih institusi ini untuk penghantaran majlis.'),
            ]);
        }

        if ($organizerType === 'speaker' && $primaryOrganizerId !== '' && ! $this->entitySubmissionAccess->canUseSpeaker($submitter, $primaryOrganizerId)) {
            throw ValidationException::withMessages([
                $this->validationKey('primary_organizer_id', $validationKeyPrefix) => __('Anda tidak dibenarkan memilih penceramah ini untuk penghantaran majlis.'),
            ]);
        }

        $eventFormat = $this->normalizeEnumValue($validated['event_format'] ?? null, EventFormat::Physical->value);
        $requiresLocationChoice = $organizerType === 'speaker' || ! ($validated['location_same_as_institution'] ?? true);
        $usesLocationInstitution = $eventFormat !== EventFormat::Online->value
            && $requiresLocationChoice
            && (($validated['location_type'] ?? 'institution') === 'institution');

        if ($usesLocationInstitution && $locationInstitutionId !== '' && ! $this->entitySubmissionAccess->canUseInstitution($submitter, $locationInstitutionId)) {
            throw ValidationException::withMessages([
                $this->validationKey('location_institution_id', $validationKeyPrefix) => __('Anda tidak dibenarkan memilih institusi lokasi ini.'),
            ]);
        }

        $speakerIds = collect(array_merge(
            (array) ($validated['speakers'] ?? []),
            collect((array) ($validated['other_key_people'] ?? []))->pluck('speaker_id')->all(),
        ))
            ->map(fn (mixed $value): ?string => filled($value) ? (string) $value : null)
            ->filter()
            ->unique()
            ->values();

        foreach ($speakerIds as $speakerId) {
            if (! $this->entitySubmissionAccess->canUseSpeaker($submitter, $speakerId)) {
                throw ValidationException::withMessages([
                    $this->validationKey('speakers', $validationKeyPrefix) => __('Senarai penceramah mengandungi pilihan yang tidak dibenarkan untuk penghantaran ini.'),
                ]);
            }
        }
    }

    private function hasCommunityEventTypeSelection(mixed $eventTypes): bool
    {
        if ($eventTypes instanceof Collection) {
            $eventTypes = $eventTypes->all();
        }

        if (! is_array($eventTypes)) {
            $eventTypes = [$eventTypes];
        }

        foreach ($eventTypes as $eventTypeValue) {
            $eventType = $eventTypeValue instanceof EventType
                ? $eventTypeValue
                : EventType::tryFrom((string) $eventTypeValue);

            if ($eventType?->isCommunity()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{event_date: string, prayer_time: string|EventPrayerTime, custom_time?: string|null, submission_country_id?: string|null, timezone?: string|null}  $validated
     */
    private function resolveStartsAt(array $validated): Carbon
    {
        $timezone = $this->resolveSubmissionTimezone($validated);
        $eventDate = Carbon::parse($validated['event_date'], $timezone)->startOfDay();
        $prayerTimeValue = $validated['prayer_time'] ?? '';
        $prayerTime = $prayerTimeValue instanceof EventPrayerTime
            ? $prayerTimeValue
            : EventPrayerTime::tryFrom((string) $prayerTimeValue);

        if ($prayerTime?->isCustomTime() && ! empty($validated['custom_time'])) {
            $time = Carbon::parse($validated['custom_time']);

            return $eventDate->setTime($time->hour, $time->minute)->utc();
        }

        $timeString = $this->defaultPrayerTimes()[$prayerTime instanceof EventPrayerTime ? $prayerTime->value : ''] ?? '20:00';
        $time = Carbon::parse($timeString);

        return $eventDate->setTime($time->hour, $time->minute)->utc();
    }

    /**
     * @param  array{end_time?: string|null}  $validated
     */
    private function resolveEndsAt(array $validated, Carbon $startsAt, string $timezone): ?Carbon
    {
        $endTimeValue = $validated['end_time'] ?? null;

        if (! is_string($endTimeValue) || $endTimeValue === '') {
            return null;
        }

        $time = Carbon::parse($endTimeValue);
        $startInUserTimezone = $startsAt->copy()->setTimezone($timezone);

        return $startInUserTimezone->setTime($time->hour, $time->minute)->utc();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateEndsAtAfterStartsAt(array $validated, Carbon $startsAt, string $timezone, string $validationKeyPrefix = ''): void
    {
        $endTimeValue = $validated['end_time'] ?? null;

        if (! is_string($endTimeValue) || $endTimeValue === '') {
            return;
        }

        $endTime = Carbon::parse($endTimeValue);
        $startInUserTimezone = $startsAt->copy()->setTimezone($timezone);
        $endInUserTimezone = $startInUserTimezone->copy()->setTime($endTime->hour, $endTime->minute);

        if ($endInUserTimezone->lessThanOrEqualTo($startInUserTimezone)) {
            throw ValidationException::withMessages([
                $this->validationKey('end_time', $validationKeyPrefix) => __('Masa akhir mestilah selepas masa mula.'),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function defaultPrayerTimes(): array
    {
        return [
            EventPrayerTime::SelepasSubuh->value => '06:30',
            EventPrayerTime::SelepasZuhur->value => '13:30',
            EventPrayerTime::SebelumJumaat->value => '13:45',
            EventPrayerTime::SelepasJumaat->value => '14:00',
            EventPrayerTime::SelepasAsar->value => '17:00',
            EventPrayerTime::SebelumMaghrib->value => '19:45',
            EventPrayerTime::SelepasMaghrib->value => '20:00',
            EventPrayerTime::SelepasIsyak->value => '21:30',
            EventPrayerTime::SelepasTarawih->value => '22:30',
        ];
    }

    private function isRamadhan(Carbon $date, ?string $timezone = null): bool
    {
        $timezone ??= config('app.timezone', 'UTC');
        $year = $date->year;
        $ramadhanPeriods = [
            2026 => ['start' => '02-18', 'end' => '03-19'],
            2027 => ['start' => '02-07', 'end' => '03-08'],
            2028 => ['start' => '01-27', 'end' => '02-25'],
            2029 => ['start' => '01-16', 'end' => '02-13'],
            2030 => ['start' => '01-05', 'end' => '02-03'],
        ];

        if (! isset($ramadhanPeriods[$year])) {
            return false;
        }

        $period = $ramadhanPeriods[$year];
        $startDate = Carbon::parse("{$year}-{$period['start']}", $timezone)->startOfDay();
        $endDate = Carbon::parse("{$year}-{$period['end']}", $timezone)->endOfDay();

        return $date->between($startDate, $endDate);
    }

    /** @param  array{submission_country_id?: string|null}  $validated */
    private function assertValidSubmissionCountryId(array $validated, string $validationKeyPrefix): void
    {
        if (! $this->submissionCountryInputProvided($validated)) {
            throw ValidationException::withMessages([
                $this->validationKey('submission_country_id', $validationKeyPrefix) => __('The submission country is required.'),
            ]);
        }

        if ($this->normalizedSubmissionCountryId($validated) !== null) {
            return;
        }

        throw ValidationException::withMessages([
            $this->validationKey('submission_country_id', $validationKeyPrefix) => __('The selected country is invalid.'),
        ]);
    }

    /** @param  array{submission_country_id?: string|null}  $validated */
    private function resolveSubmissionCountryId(array $validated): string
    {
        $normalizedCountryId = $this->normalizedSubmissionCountryId($validated);

        if (is_string($normalizedCountryId)) {
            return $normalizedCountryId;
        }

        throw ValidationException::withMessages([
            'submission_country_id' => __('The selected country is invalid.'),
        ]);
    }

    /** @param  array{submission_country_id?: string|null}  $validated */
    private function resolveSubmissionTimezone(array $validated, ?string $submissionCountryId = null): string
    {
        $resolvedCountryId = $submissionCountryId ?? $this->resolveSubmissionCountryId($validated);

        return app(AddressCountryResolver::class)->timezoneFor($resolvedCountryId)
            ?? config('app.timezone', 'UTC');
    }

    /** @param  array{submission_country_id?: string|null}  $validated */
    private function submissionCountryInputProvided(array $validated): bool
    {
        $value = $validated['submission_country_id'] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /** @param  array{submission_country_id?: string|null}  $validated */
    private function normalizedSubmissionCountryId(array $validated): ?string
    {
        $resolvedCountryId = app(AddressCountryResolver::class)->resolveId($validated['submission_country_id'] ?? null);

        if (is_string($resolvedCountryId)) {
            return $resolvedCountryId;
        }

        return null;
    }

    /**
     * @param  array{submitter_email?: string|null, submitter_phone?: string|null}  $validated
     */
    private function storeSubmitterContacts(EventSubmission $submission, array $validated): void
    {
        $email = $validated['submitter_email'] ?? null;
        $phone = $validated['submitter_phone'] ?? null;
        $order = 1;

        if (filled($email)) {
            $submission->contactMethods()->create([
                'type' => ContactMethodType::Email->value,
                'purpose' => ContactPurpose::General->value,
                'value' => $email,
                'is_public' => false,
                'sort_order' => $order++,
            ]);
        }

        if (filled($phone)) {
            $submission->contactMethods()->create([
                'type' => ContactMethodType::Phone->value,
                'purpose' => ContactPurpose::General->value,
                'value' => $phone,
                'is_public' => false,
                'sort_order' => $order++,
            ]);
        }
    }

    private function assertCaptchaIsValid(Request $request, ?string $captchaToken, string $validationKeyPrefix = ''): void
    {
        if (! $this->turnstileVerifier->verify($captchaToken, $request->ip())) {
            throw ValidationException::withMessages([
                $this->validationKey('captcha_token', $validationKeyPrefix) => __('Sila lengkapkan pengesahan keselamatan sebelum menghantar.'),
            ]);
        }
    }

    private function persistRegistrationSettings(Event $event, ?Event $parentEvent): void
    {
        if ($parentEvent instanceof Event && $parentEvent->accessPolicy !== null) {
            $resolvedRegistrationMode = $parentEvent->resolvedRegistrationMode();

            $event->forceFill([
                'registration_mode' => $resolvedRegistrationMode->value,
            ])->save();

            $event->accessPolicy()->updateOrCreate(
                ['event_id' => $event->getKey()],
                [
                    'registration_required' => (bool) $parentEvent->accessPolicy->registration_required,
                    'walk_in_allowed' => ! $parentEvent->accessPolicy->registration_required,
                ],
            );

            return;
        }

        $event->forceFill([
            'registration_mode' => RegistrationMode::None->value,
        ])->save();

        $event->accessPolicy()->updateOrCreate(
            ['event_id' => $event->getKey()],
            [
                'registration_required' => false,
                'walk_in_allowed' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Institution|Speaker, 1: string|null, 2: string|null}
     */
    private function resolveOrganizerAndLocation(array $validated, string $validationKeyPrefix = ''): array
    {
        $primaryOrganizer = $this->resolvePrimaryOrganizer($validated['primary_organizer_id'] ?? null);

        if (! $primaryOrganizer instanceof Institution && ! $primaryOrganizer instanceof Speaker) {
            throw ValidationException::withMessages([
                $this->validationKey('primary_organizer_id', $validationKeyPrefix) => __('Sila pilih penganjur utama.'),
            ]);
        }

        $targetInstitutionId = null;
        $targetVenueId = null;
        $locationType = $validated['location_type'] ?? 'institution';
        $locationInstitutionId = $validated['location_institution_id'] ?? null;
        $venueId = $validated['location_venue_id'] ?? null;

        if ($locationType === 'institution' && $locationInstitutionId) {
            $venueId = null;
        } elseif ($locationType === 'venue' && $venueId) {
            $locationInstitutionId = null;
        }

        if ($primaryOrganizer instanceof Institution) {
            if (($validated['location_same_as_institution'] ?? true) == true) {
                $targetInstitutionId = (string) $primaryOrganizer->getKey();
            } elseif (($validated['location_type'] ?? null) === 'institution') {
                $targetInstitutionId = $validated['location_institution_id'] ?? null;
            } else {
                $targetVenueId = $validated['location_venue_id'] ?? null;
            }
        } else {
            if ($locationInstitutionId) {
                $targetInstitutionId = $locationInstitutionId;
            } elseif ($venueId) {
                $targetVenueId = $venueId;
            }
        }

        return [$primaryOrganizer, $targetInstitutionId, $targetVenueId];
    }

    private function resolvePrimaryOrganizer(mixed $primaryOrganizerId): Institution|Speaker|null
    {
        $organizerId = is_string($primaryOrganizerId) ? trim($primaryOrganizerId) : '';

        if ($organizerId === '') {
            return null;
        }

        return Institution::query()->find($organizerId)
            ?? Speaker::query()->find($organizerId);
    }

    private function resolvePrimaryOrganizerKind(mixed $primaryOrganizerId): ?string
    {
        $organizer = $this->resolvePrimaryOrganizer($primaryOrganizerId);

        return match (true) {
            $organizer instanceof Institution => 'institution',
            $organizer instanceof Speaker => 'speaker',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeEnumState(array $validated): array
    {
        $validated['event_format'] = $this->normalizeEnumValue($validated['event_format'] ?? null, EventFormat::Physical->value);
        $validated['visibility'] = $this->normalizeEnumValue($validated['visibility'] ?? null, EventVisibility::Public->value);
        $validated['gender'] = $this->normalizeEnumValue($validated['gender'] ?? null, EventGenderRestriction::All->value);
        $validated['prayer_time'] = $this->normalizeEnumValue($validated['prayer_time'] ?? null, '');
        $validated['event_type'] = $this->normalizeEnumList($validated['event_type'] ?? []);
        $validated['age_group'] = $this->normalizeEnumList($validated['age_group'] ?? []);

        return $validated;
    }

    private function normalizeEnumValue(mixed $value, string $default = ''): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    private function normalizeEnumList(mixed $values): array
    {
        if ($values instanceof Collection) {
            $values = $values->all();
        }

        if (! is_array($values)) {
            $values = [$values];
        }

        return collect($values)
            ->map(fn (mixed $value): string => $this->normalizeEnumValue($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    private function validationKey(string $field, string $validationKeyPrefix = ''): string
    {
        return $validationKeyPrefix === '' ? $field : $validationKeyPrefix.$field;
    }
}
