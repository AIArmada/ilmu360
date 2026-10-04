<?php

namespace App\Actions\Events;

use AIArmada\Events\Models\EventSession;
use App\Actions\Prayer\ResolvePrayerStartClockAction;
use App\Contracts\CaptchaVerifier;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Support\Prayer\PrayerLocation;
use App\Support\Submission\EntitySubmissionAccess;
use App\Support\Submission\SubmissionContextResolver;
use App\Support\Submission\SubmissionRelationSync;
use App\Support\Submission\SubmissionTimingPolicy;
use App\Support\Submission\SubmissionValues;
use App\Support\Submission\SubmitterContactRules;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class SubmitFrontendEventAction
{
    use AsAction;

    public function __construct(
        private readonly EntitySubmissionAccess $entitySubmissionAccess,
        private readonly CaptchaVerifier $turnstileVerifier,
        private readonly GenerateEventSlugAction $generateEventSlugAction,
        private readonly PersistNewEventSubmissionAction $persistNewEvent,
        private readonly PersistSessionSubmissionAction $persistSession,
        private readonly CompleteFrontendEventSubmissionAction $completeSubmission,
        private readonly SubmissionTimingPolicy $timing,
        private readonly SubmissionContextResolver $context,
        private readonly ValidateEventSubmissionInputAction $validateSubmissionInput,
        private readonly SubmissionRelationSync $relationSync,
        private readonly ResolvePrayerStartClockAction $resolveStartClock,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @param  (callable(HasMedia): void)|null  $persistRelationships
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
        $scopedInstitution = $this->context->authorizedScopedInstitution($scopedInstitution, $submitter, $validationKeyPrefix, $state);
        $eventContainer = $this->context->authorizedEventContainer($eventContainer, $submitter, $scopedInstitution, $validationKeyPrefix);

        // Structural boundary: reject malformed shapes, invalid explicit enums,
        // non-UUID IDs, and bad booleans before any scoped coercion, captcha
        // spend, or write. Defaults apply only when the input key is truly
        // omitted.
        $validated = $this->validateSubmissionInput->handle($state, $validationKeyPrefix);

        // Semantic boundary: unknown, wrong-taxonomy, or inactive category IDs
        // must fail here. Catalog normalization silently drops such IDs, so an
        // unchecked result could turn a required selection into [].
        $catalog = app(EventCategoryCatalog::class);
        $requestedCategoryIds = is_array($validated['event_category_ids'] ?? null) ? $validated['event_category_ids'] : [];
        $normalizedRequestedCategoryIds = array_values(array_unique(array_map(static fn (mixed $id): string => (string) $id, $requestedCategoryIds)));
        $knownCategoryIds = $catalog->validTermIds($normalizedRequestedCategoryIds);

        if (array_diff($normalizedRequestedCategoryIds, $knownCategoryIds) !== []) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_category_ids', $validationKeyPrefix) => __('Pilihan kategori majlis tidak sah.'),
            ]);
        }

        $validated['event_category_ids'] = $catalog->validateTermIds($knownCategoryIds);
        $validated = $this->context->normalizeScopedInstitutionState($validated, $scopedInstitution, $validationKeyPrefix);
        $validated = $this->context->normalizeSpaceSelection($validated);

        $submissionCountryId = $this->context->resolveSubmissionCountryId($validated, $validationKeyPrefix);
        $submissionCountryIso2 = $this->timing->countryIso2ForId($submissionCountryId);
        $timezone = $this->timing->resolveSubmissionTimezone($submissionCountryId, $validated['submission_timezone'] ?? null, $validationKeyPrefix);
        $primaryOrganizer = $this->context->resolvePrimaryOrganizer($validated['primary_organizer_id'] ?? null);
        $organizerKind = match (true) {
            $primaryOrganizer instanceof Institution => 'institution',
            $primaryOrganizer instanceof Person => 'person',
            default => null,
        };

        $this->context->assertConditionalRequirements($validated, $organizerKind, $validationKeyPrefix);

        $ageGroups = $validated['age_group'] ?? [];

        if (
            in_array(EventAgeGroup::Children->value, $ageGroups, true)
            || in_array(EventAgeGroup::AllAges->value, $ageGroups, true)
        ) {
            $validated['children_allowed'] = true;
        }

        $this->entitySubmissionAccess->assertSubmissionEntitiesAreAccessible($validated, $submitter, $organizerKind, $validationKeyPrefix);
        $this->relationSync->assertCatalogSelectionsAreAvailable($validated, $validationKeyPrefix);
        SubmitterContactRules::assertSubmitterContactsAreValid($validated, $submitter, $validationKeyPrefix);
        $this->entitySubmissionAccess->assertSubmissionEntitiesMatchCountry($validated, $organizerKind, $submissionCountryId, $scopedInstitution, $validationKeyPrefix);

        if (
            $this->hasCommunityCategorySelection($validated['event_category_ids'] ?? [], $validationKeyPrefix)
            && (($validated['event_format'] ?? EventFormat::Physical->value) !== EventFormat::Physical->value)
        ) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_format', $validationKeyPrefix) => __('Jenis majlis komuniti mesti menggunakan format fizikal.'),
            ]);
        }

        $prayerTime = $this->timing->resolvePrayerTime($validated['prayer_time'] ?? null, $validationKeyPrefix);
        $eventDate = $this->timing->parseEventDate($validated['event_date'] ?? null, $timezone, $validationKeyPrefix);
        $this->timing->assertPrayerDateIsAllowed($prayerTime, $eventDate, $timezone, $validationKeyPrefix, $submissionCountryIso2);

        if (! $primaryOrganizer instanceof Institution && ! $primaryOrganizer instanceof Person) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('primary_organizer_id', $validationKeyPrefix) => __('Sila pilih penganjur utama.'),
            ]);
        }

        [$targetInstitutionId, $targetVenueId] = $this->context->resolveTargetLocation($validated, $primaryOrganizer);
        $this->context->assertSpaceSelectionIsEligible($validated, $targetInstitutionId, $targetVenueId, $validationKeyPrefix);

        // Tarawih has no prayer anchor; it is stored as a label-only expression.
        $prayerReference = $prayerTime === EventPrayerTime::SelepasTarawih
            ? null
            : $prayerTime->toPrayerReference()?->value;
        $prayerOffset = $prayerTime->getDefaultOffset();
        $prayerDisplayText = $prayerTime->isCustomTime() ? null : $prayerTime->getLabel();

        // Provider-backed start clock. Null unless the flag is on and provider
        // data is cached; the policy falls back to hardcoded estimates.
        $resolvedStart = $this->resolveProviderStartClock(
            $targetInstitutionId,
            $targetVenueId,
            $submissionCountryIso2,
            $timezone,
            $prayerTime,
            $eventDate,
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

        $occurrenceId = $this->context->resolveSelectedOccurrenceId($validated, $eventContainer, $validationKeyPrefix);

        // Captcha runs after every cheap local check so a field error never
        // burns the single-use token (or the verification HTTP call).
        $this->assertCaptchaIsValid($request, $validated['captcha_token'] ?? null, $validationKeyPrefix);

        $autoApproved = $scopedInstitution instanceof Institution;
        $personSlugSegments = $this->generateEventSlugAction->personSlugSegmentsForState(
            is_array($validated['persons'] ?? null) ? $validated['persons'] : [],
            $primaryOrganizer,
        );

        $endsAt = $this->timing->resolveEndsAt($validated['end_time'] ?? null, $startsAt, $timezone, $validationKeyPrefix);
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
            prayerReference: $prayerReference,
            prayerOffset: $prayerOffset?->minutes(),
            prayerDisplayText: $prayerDisplayText,
            prayerSource: $resolvedStart['source'] ?? null,
            prayerFetchedAt: $resolvedStart['fetched_at'] ?? null,
            prayerZone: $resolvedStart['zone'] ?? null,
            prayerLat: isset($resolvedStart['lat']) && is_numeric($resolvedStart['lat']) ? (float) $resolvedStart['lat'] : null,
            prayerLng: isset($resolvedStart['lng']) && is_numeric($resolvedStart['lng']) ? (float) $resolvedStart['lng'] : null,
            prayerCountry: $resolvedStart['country'] ?? $submissionCountryIso2,
            autoApproved: $autoApproved,
            sessionSubmission: $isSessionSubmission,
            submitter: $submitter,
            eventContainer: $eventContainer,
            personSlugSegments: $personSlugSegments,
            occurrenceId: $occurrenceId,
            validationKeyPrefix: $validationKeyPrefix,
        );

        $persisted = DB::transaction(function () use (
            $validatedSubmission,
            $persistRelationships,
            $validated,
            $request,
            $submitter,
            $isSessionSubmission,
            $autoApproved,
        ): array {
            $mediaSubject = null;
            $persistMedia = $persistRelationships === null ? null : function (HasMedia $subject) use ($persistRelationships, &$mediaSubject): void {
                $mediaSubject = $subject;
                $persistRelationships($subject);
            };

            try {
                $persisted = $isSessionSubmission
                    ? $this->persistSession->handle($validatedSubmission, $persistMedia)
                    : $this->persistNewEvent->handle($validatedSubmission, $persistMedia);
                $event = $persisted['event'];
                $submission = $persisted['submission'];

                $this->completeSubmission->handle(
                    event: $event,
                    submission: $submission,
                    state: $validated,
                    submitter: $submitter,
                    sessionSubmission: $isSessionSubmission,
                    autoApproved: $autoApproved,
                );

                DB::afterCommit(function () use ($event, $submission, $validated, $request, $submitter): void {
                    $this->completeSubmission->handleAfterCommit(
                        event: $event,
                        submission: $submission,
                        state: $validated,
                        request: $request,
                        submitter: $submitter,
                    );
                });

                return $persisted;
            } catch (Throwable $exception) {
                if ($mediaSubject instanceof HasMedia) {
                    $this->removeFailedSubmissionMedia($mediaSubject);
                }

                throw $exception;
            }
        });

        $event = $persisted['event'];
        $session = $persisted['session'];
        $submission = $persisted['submission'];

        // Sessions own their visibility; the confirmation must reflect the saved
        // subject, never the parent event when they differ.
        $subjectVisibility = $session instanceof EventSession ? $session->visibility : $event->visibility;

        // Session submissions schedule immediately (package status: scheduled)
        // without event moderation; parent auto_approved must not claim the new
        // session was published.
        $effectiveAutoApproved = $session instanceof EventSession ? false : $autoApproved;

        return [
            'event' => $event->fresh() ?? $event,
            'session' => $session?->fresh() ?? $session,
            'submission' => $submission->fresh() ?? $submission,
            'auto_approved' => $effectiveAutoApproved,
            'visibility' => $subjectVisibility instanceof EventVisibility ? $subjectVisibility->value : (string) $subjectVisibility,
        ];
    }

    private function removeFailedSubmissionMedia(HasMedia $subject): void
    {
        try {
            /** @var \Illuminate\Database\Eloquent\Collection<int, Media> $mediaItems */
            $mediaItems = $subject->media()->get();
        } catch (Throwable $exception) {
            Log::warning('Could not resolve media for a failed event submission.', ['exception' => $exception]);

            return;
        }

        foreach ($mediaItems as $media) {
            try {
                foreach (array_keys($media->responsive_images) as $conversionName) {
                    if (is_string($conversionName)) {
                        $media->responsiveImages($conversionName === 'media_library_original' ? '' : $conversionName)->delete();
                    }
                }

                $filesystem = app(Filesystem::class);
                $filesystem->removeAllFiles($media);
                $filesystem->removeResponsiveImages($media);
            } catch (Throwable $exception) {
                Log::warning('Could not remove media for a failed event submission.', [
                    'media_id' => $media->getKey(),
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function hasCommunityCategorySelection(mixed $categoryIds, string $validationKeyPrefix = ''): bool
    {
        if ($categoryIds instanceof Collection) {
            $categoryIds = $categoryIds->all();
        }

        if (! is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }

        try {
            return app(EventCategoryPolicyResolver::class)->requiresPhysicalDelivery(
                app(EventCategoryCatalog::class)->validateTermIds($categoryIds),
            );
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $fieldMessages) {
                foreach ((array) $fieldMessages as $message) {
                    $messages[SubmissionValues::prefixedKey('event_category_ids', $validationKeyPrefix)][] = $message;
                }
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @return array{clock: string, date: string, source: string, fetched_at: string, zone: string|null}|null
     */
    private function resolveProviderStartClock(
        ?string $targetInstitutionId,
        ?string $targetVenueId,
        ?string $submissionCountryIso2,
        string $timezone,
        EventPrayerTime $prayerTime,
        CarbonInterface $eventDate,
    ): ?array {
        $address = PrayerLocation::forTargets($targetVenueId, $targetInstitutionId);
        $location = PrayerLocation::fromAddress($address, $submissionCountryIso2);

        return $this->resolveStartClock->handle(
            countryCode: $location['countryCode'] ?? 'MY',
            date: $eventDate->format('Y-m-d'),
            timezone: $timezone,
            prayerTime: $prayerTime,
            latitude: $location['latitude'],
            longitude: $location['longitude'],
            stateCode: $location['stateCode'],
            districtCandidates: $location['districtCandidates'],
        );
    }

    private function assertCaptchaIsValid(Request $request, ?string $captchaToken, string $validationKeyPrefix = ''): void
    {
        if (! $this->turnstileVerifier->verify($captchaToken, $request->ip())) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('captcha_token', $validationKeyPrefix) => __('Sila lengkapkan pengesahan keselamatan sebelum menghantar.'),
            ]);
        }
    }
}
