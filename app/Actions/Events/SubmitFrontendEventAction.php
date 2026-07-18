<?php

namespace App\Actions\Events;

use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\Events\Models\EventSession;
use App\Contracts\CaptchaVerifier;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Submission\EntitySubmissionAccess;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class SubmitFrontendEventAction
{
    use AsAction;

    public function __construct(
        private readonly EntitySubmissionAccess $entitySubmissionAccess,
        private readonly CaptchaVerifier $turnstileVerifier,
        private readonly PersistValidatedEventSubmissionAction $persistValidatedSubmission,
        private readonly CompleteFrontendEventSubmissionAction $completeSubmission,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @param  (callable(Event): void)|null  $persistRelationships
     * @return array{event: Event, session: EventSession|null, submission: EventSubmission, auto_approved: bool, visibility: string}
     */
    public function handle(
        array $state,
        Request $request,
        ?User $submitter = null,
        ?Event $eventContainer = null,
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
            $this->hasCommunityCategorySelection($validated['event_category_ids'] ?? [])
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

        $endsAt = $this->resolveEndsAt($validated, $startsAt, $timezone);
        $isSessionSubmission = $eventContainer instanceof Event;
        $validatedSubmission = new ValidatedEventSubmission(
            state: $validated,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            primaryOrganizer: $primaryOrganizer,
            targetInstitutionId: $targetInstitutionId,
            targetVenueId: $targetVenueId,
            prayerTime: $prayerTime,
            prayerReference: $prayerReference?->value,
            prayerOffset: $prayerOffset?->minutes(),
            prayerDisplayText: $prayerDisplayText,
            autoApproved: $autoApproved,
            sessionSubmission: $isSessionSubmission,
            submitter: $submitter,
            eventContainer: $eventContainer,
            speakerSlugSegments: $speakerSlugSegments,
        );
        $persisted = DB::transaction(fn (): array => $this->persistValidatedSubmission->handle($validatedSubmission, $persistRelationships));
        $event = $persisted['event'];
        $session = $persisted['session'];
        $submission = $persisted['submission'];

        DB::afterCommit(function () use ($event, $submission, $validated, $request, $submitter, $isSessionSubmission, $autoApproved): void {
            $this->completeSubmission->handle(
                event: $event,
                submission: $submission,
                state: $validated,
                request: $request,
                submitter: $submitter,
                sessionSubmission: $isSessionSubmission,
                autoApproved: $autoApproved,
            );
        });

        $visibility = $event->visibility;

        return [
            'event' => $event->fresh() ?? $event,
            'session' => $session?->fresh() ?? $session,
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

    private function hasCommunityCategorySelection(mixed $categoryIds): bool
    {
        if ($categoryIds instanceof Collection) {
            $categoryIds = $categoryIds->all();
        }

        if (! is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }

        return app(EventCategoryPolicyResolver::class)->requiresPhysicalDelivery(
            app(EventCategoryCatalog::class)->validateTermIds($categoryIds),
        );
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

    private function assertCaptchaIsValid(Request $request, ?string $captchaToken, string $validationKeyPrefix = ''): void
    {
        if (! $this->turnstileVerifier->verify($captchaToken, $request->ip())) {
            throw ValidationException::withMessages([
                $this->validationKey('captcha_token', $validationKeyPrefix) => __('Sila lengkapkan pengesahan keselamatan sebelum menghantar.'),
            ]);
        }
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
        } elseif ($locationInstitutionId) {
            $targetInstitutionId = $locationInstitutionId;
        } elseif ($venueId) {
            $targetVenueId = $venueId;
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
        $validated['event_category_ids'] = app(EventCategoryCatalog::class)->validateTermIds(
            $this->normalizeEnumList($validated['event_category_ids'] ?? []),
        );
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
