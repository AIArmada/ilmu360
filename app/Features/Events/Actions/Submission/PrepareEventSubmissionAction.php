<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use App\Features\Contributions\Api\ContributionOrganizer;
use App\Features\Contributions\Api\SubmissionEntityAccess;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventSubmissionInput;
use App\Features\Events\Api\EventSubmissionOrganizerKind;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Api\SubmissionValues;
use App\Features\Events\Models\Event;
use App\Features\Events\Services\SubmissionContextResolver;
use App\Features\Events\Support\SubmissionTimingPolicy;
use App\Features\Events\Support\SubmitterContactRules;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class PrepareEventSubmissionAction
{
    public function __construct(
        private SubmissionEntityAccess $entitySubmissionAccess,
        private EventAuthoring $authoring,
        private EventSubmissions $submissions,
        private SubmissionTimingPolicy $timing,
        private SubmissionContextResolver $context,
    ) {}

    /** @param array<string, mixed> $state */
    public function handle(
        array $state,
        ?User $submitter = null,
        ?Event $eventContainer = null,
        ?string $scopedInstitutionId = null,
        string $validationKeyPrefix = '',
    ): EventSubmissionInput {
        $scopedInstitutionId = $this->context->authorizedScopedInstitutionFor($scopedInstitutionId, $submitter, $validationKeyPrefix, $state);

        $resolvedEventContainer = $this->context->authorizedEventContainerFor($eventContainer, $submitter, $scopedInstitutionId, $validationKeyPrefix);
        $eventContainer = $resolvedEventContainer instanceof Event ? $resolvedEventContainer : null;

        $validated = $this->submissions->validate($state, $validationKeyPrefix);

        $validated = $this->submissions->normalizeCategories($validated, $validationKeyPrefix);
        $validated = $this->context->normalizeScopedInstitutionStateFor($validated, $scopedInstitutionId, $validationKeyPrefix);
        $validated = $this->context->normalizeSpaceSelection($validated);

        $submissionCountryId = $this->context->resolveSubmissionCountryId($validated, $validationKeyPrefix);
        $submissionCountryIso2 = $this->timing->countryIso2ForId($submissionCountryId);
        $timezone = $this->timing->resolveSubmissionTimezone($submissionCountryId, $validated['submission_timezone'] ?? null, $validationKeyPrefix);
        $primaryOrganizer = $this->context->resolvePrimaryOrganizer($validated['primary_organizer_id'] ?? null);
        $organizerKind = $primaryOrganizer instanceof ContributionOrganizer ? $primaryOrganizer->kind : null;

        $this->context->assertConditionalRequirements($validated, $organizerKind, $validationKeyPrefix);

        $ageGroups = $validated['age_group'] ?? [];

        if (
            in_array(EventAgeGroup::Children->value, $ageGroups, true)
            || in_array(EventAgeGroup::AllAges->value, $ageGroups, true)
        ) {
            $validated['children_allowed'] = true;
        }

        $this->entitySubmissionAccess->assertSubmissionEntitiesAreAccessible($validated, $submitter, $organizerKind, $validationKeyPrefix);
        $this->submissions->assertCatalogSelectionsAreAvailable($validated, $validationKeyPrefix);
        SubmitterContactRules::assertSubmitterContactsAreValid($validated, $submitter, $validationKeyPrefix);
        $this->entitySubmissionAccess->assertSubmissionEntitiesMatchCountryFor($validated, $organizerKind, $submissionCountryId, $scopedInstitutionId, $validationKeyPrefix);

        $this->submissions->assertCommunityPhysicalFormat($validated, $validationKeyPrefix);

        $prayerTime = $this->timing->resolvePrayerTime($validated['prayer_time'] ?? null, $validationKeyPrefix);
        $eventDate = $this->timing->parseEventDate($validated['event_date'] ?? null, $timezone, $validationKeyPrefix);
        $this->timing->assertPrayerDateIsAllowed($prayerTime, $eventDate, $timezone, $validationKeyPrefix, $submissionCountryIso2);

        if (! $primaryOrganizer instanceof ContributionOrganizer) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('primary_organizer_id', $validationKeyPrefix) => __('Sila pilih penganjur utama.'),
            ]);
        }

        $organizerKind = $primaryOrganizer->kind;

        [$targetInstitutionId, $targetVenueId] = $this->context->resolveTargetLocationFor($validated, $primaryOrganizer);
        $this->context->assertSpaceSelectionIsEligible($validated, $targetInstitutionId, $targetVenueId, $validationKeyPrefix);

        $prayerReference = $prayerTime === EventPrayerTime::SelepasTarawih
            ? null
            : $prayerTime->toPrayerReference()?->value;
        $prayerOffset = $prayerTime->getDefaultOffset();
        $prayerDisplayText = $prayerTime->isCustomTime() ? null : $prayerTime->getLabel();

        $resolvedStart = $this->submissions->resolvePrayerStartClock(
            $targetInstitutionId,
            $targetVenueId,
            $submissionCountryIso2,
            $timezone,
            $prayerTime->value,
            $eventDate->format('Y-m-d'),
        );

        $startsAt = $this->timing->resolveStartsAt(
            $validated['event_date'] ?? null,
            $prayerTime,
            $validated['custom_time'] ?? null,
            $timezone,
            $validationKeyPrefix,
            $resolvedStart['clock'] ?? null,
            $resolvedStart['date'] ?? null,
            $resolvedStart['starts_at'] ?? null,
        );
        $this->timing->validateEndsAtAfterStartsAt($validated['end_time'] ?? null, $startsAt, $timezone, $validationKeyPrefix);
        $this->timing->validateStartsAtIsFuture($startsAt, $timezone, $prayerTime, $validationKeyPrefix);

        $occurrenceId = $this->context->resolveSelectedOccurrenceIdFor($validated, $eventContainer, $validationKeyPrefix);

        $autoApproved = $scopedInstitutionId !== null;
        $personSlugSegments = $this->authoring->personSlugSegmentsForState(
            is_array($validated['persons'] ?? null) ? $validated['persons'] : [],
            $organizerKind,
            $primaryOrganizer->id,
        );

        $endsAt = $this->timing->resolveEndsAt($validated['end_time'] ?? null, $startsAt, $timezone, $validationKeyPrefix);
        $isSessionSubmission = $eventContainer instanceof Event;

        return new EventSubmissionInput(
            state: $validated,
            startsAt: $startsAt->toIso8601String(),
            endsAt: $endsAt?->toIso8601String(),
            timezone: $timezone,
            primaryOrganizerKind: EventSubmissionOrganizerKind::from($organizerKind),
            primaryOrganizerId: $primaryOrganizer->id,
            targetInstitutionId: $targetInstitutionId,
            targetVenueId: $targetVenueId,
            prayerTime: $prayerTime->value,
            prayerReference: $prayerReference,
            prayerOffset: $prayerOffset?->minutes(),
            prayerDisplayText: $prayerDisplayText,
            autoApproved: $autoApproved,
            sessionSubmission: $isSessionSubmission,
            submitterId: $submitter instanceof User ? (string) $submitter->getKey() : null,
            eventContainerId: $eventContainer instanceof Event ? (string) $eventContainer->getKey() : null,
            personSlugSegments: $personSlugSegments,
            occurrenceId: $occurrenceId,
            validationKeyPrefix: $validationKeyPrefix,
            prayerSource: $resolvedStart['source'] ?? null,
            prayerFetchedAt: $resolvedStart['fetched_at'] ?? null,
            prayerZone: $resolvedStart['zone'] ?? null,
            prayerLat: isset($resolvedStart['lat']) && is_numeric($resolvedStart['lat']) ? (float) $resolvedStart['lat'] : null,
            prayerLng: isset($resolvedStart['lng']) && is_numeric($resolvedStart['lng']) ? (float) $resolvedStart['lng'] : null,
            prayerCountry: $resolvedStart['country'] ?? $submissionCountryIso2,
        );
    }
}
