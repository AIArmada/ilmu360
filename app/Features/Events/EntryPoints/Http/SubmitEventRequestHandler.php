<?php

namespace App\Features\Events\EntryPoints\Http;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Contributions\Services\EntitySubmissionAccess;
use App\Features\Contributions\Services\SubmissionContextResolver;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventSubmissionCompletion;
use App\Features\Events\Api\EventSubmissionInput;
use App\Features\Events\Api\EventSubmissionOrganizerKind;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Events\Notifications\EventOccurrenceSubmittedNotification;
use App\Features\Events\Support\SubmissionTimingPolicy;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Features\ShareTracking\Api\ShareTrackingContract;
use App\Models\User;
use App\Shared\Captcha\CaptchaVerifier;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareRequestContext;
use App\Shared\ShareTracking\ShareSubjectType;
use App\Shared\Submission\SubmissionValues;
use App\Shared\Submission\SubmitterContactRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

final readonly class SubmitEventRequestHandler
{
    public function __construct(
        private EntitySubmissionAccess $entitySubmissionAccess,
        private CaptchaVerifier $turnstileVerifier,
        private EventAuthoring $authoring,
        private EventSubmissions $submissions,
        private EventSubmissionCompletion $submissionCompletion,
        private ShareTrackingContract $shareTracking,
        private SubmissionTimingPolicy $timing,
        private SubmissionContextResolver $context,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @param  (callable(HasMedia): void)|null  $persistRelationships
     * @return array{event: Event, session: EventSession|null, occurrence: EventOccurrence|null, submission: EventSubmission, auto_approved: bool, visibility: string, grouped: bool, parent_created: bool, occurrence_pending_review: bool}
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
        $validated = $this->submissions->validate($state, $validationKeyPrefix);

        $validated = $this->submissions->normalizeCategories($validated, $validationKeyPrefix);
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
        $this->submissions->assertCatalogSelectionsAreAvailable($validated, $validationKeyPrefix);
        SubmitterContactRules::assertSubmitterContactsAreValid($validated, $submitter, $validationKeyPrefix);
        $this->entitySubmissionAccess->assertSubmissionEntitiesMatchCountry($validated, $organizerKind, $submissionCountryId, $scopedInstitution, $validationKeyPrefix);

        $this->submissions->assertCommunityPhysicalFormat($validated, $validationKeyPrefix);

        $prayerTime = $this->timing->resolvePrayerTime($validated['prayer_time'] ?? null, $validationKeyPrefix);
        $eventDate = $this->timing->parseEventDate($validated['event_date'] ?? null, $timezone, $validationKeyPrefix);
        $this->timing->assertPrayerDateIsAllowed($prayerTime, $eventDate, $timezone, $validationKeyPrefix, $submissionCountryIso2);

        if (! $primaryOrganizer instanceof Institution && ! $primaryOrganizer instanceof Person) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('primary_organizer_id', $validationKeyPrefix) => __('Sila pilih penganjur utama.'),
            ]);
        }

        $organizerKind = $primaryOrganizer instanceof Institution ? 'institution' : 'person';

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

        $occurrenceId = $this->context->resolveSelectedOccurrenceId($validated, $eventContainer, $validationKeyPrefix);

        // Captcha runs after every cheap local check so a field error never
        // burns the single-use token (or the verification HTTP call).
        $this->assertCaptchaIsValid($request, $validated['captcha_token'] ?? null, $validationKeyPrefix);

        $autoApproved = $scopedInstitution instanceof Institution;
        $personSlugSegments = $this->authoring->personSlugSegmentsForState(
            is_array($validated['persons'] ?? null) ? $validated['persons'] : [],
            $organizerKind,
            (string) $primaryOrganizer->getKey(),
        );

        $endsAt = $this->timing->resolveEndsAt($validated['end_time'] ?? null, $startsAt, $timezone, $validationKeyPrefix);
        $isSessionSubmission = $eventContainer instanceof Event;
        $submissionInput = new EventSubmissionInput(
            state: $validated,
            startsAt: $startsAt->toIso8601String(),
            endsAt: $endsAt?->toIso8601String(),
            timezone: $timezone,
            primaryOrganizerKind: EventSubmissionOrganizerKind::from($organizerKind),
            primaryOrganizerId: (string) $primaryOrganizer->getKey(),
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

        $persisted = DB::transaction(function () use (
            $submissionInput,
            $persistRelationships,
            $validated,
            $request,
            $submitter,
            $isSessionSubmission,
            $autoApproved,
        ): array {
            $mediaSubject = null;

            try {
                $persisted = $isSessionSubmission
                    ? $this->submissions->persistSession($submissionInput)
                    : $this->submissions->persistNew($submissionInput);

                $submissionId = $persisted['submission_id'];

                if (! is_string($submissionId)) {
                    throw new \RuntimeException('The submission persistence boundary did not return a submission id.');
                }

                // The boundary returns scalar identifiers; reload the root
                // models that completion, notifications and the caller use.
                $event = Event::query()->findOrFail($persisted['event_id']);
                $submission = EventSubmission::query()->findOrFail($submissionId);
                $occurrence = is_string($persisted['occurrence_id'])
                    ? EventOccurrence::query()->find($persisted['occurrence_id'])
                    : null;
                $session = is_string($persisted['session_id'])
                    ? EventSession::query()->find($persisted['session_id'])
                    : null;

                if ($persistRelationships !== null) {
                    $mediaSubject = $session instanceof EventSession ? $session : $occurrence;

                    if (! $mediaSubject instanceof HasMedia) {
                        throw new \RuntimeException('The persisted event submission target must support media relationships.');
                    }

                    $persistRelationships($mediaSubject);
                }

                $this->completeAndRecordSubmissionOutcome(
                    event: $event,
                    submission: $submission,
                    state: $validated,
                    submitter: $submitter,
                    sessionSubmission: $isSessionSubmission,
                    autoApproved: $autoApproved,
                    request: $request,
                    occurrence: $occurrence instanceof EventOccurrence ? $occurrence : null,
                    parentCreated: $persisted['parent_created'],
                    groupedSubmission: $persisted['grouped'],
                    occurrencePendingReview: $persisted['occurrence_pending_review'],
                );

                return $persisted + [
                    'event' => $event,
                    'session' => $session,
                    'occurrence' => $occurrence,
                    'submission' => $submission,
                ];
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
        $occurrence = $persisted['occurrence'];
        $occurrence = $occurrence instanceof EventOccurrence ? $occurrence->fresh() ?? $occurrence : null;

        // Session and occurrence submissions own their visibility; the
        // confirmation follows the submitted date rather than its parent.
        $subjectVisibility = $session instanceof EventSession
            ? $session->visibility
            : ($occurrence instanceof EventOccurrence ? $occurrence->visibility : $event->visibility);

        // Session submissions schedule immediately (package status: scheduled)
        // without event moderation; parent auto_approved must not claim the new
        // session was published.
        $effectiveAutoApproved = $session instanceof EventSession || ($persisted['occurrence_pending_review'] ?? false)
            ? false
            : $autoApproved;

        return [
            'event' => $event->fresh() ?? $event,
            'session' => $session?->fresh() ?? $session,
            'occurrence' => $occurrence,
            'submission' => $submission->fresh() ?? $submission,
            'auto_approved' => $effectiveAutoApproved,
            'visibility' => $subjectVisibility instanceof EventVisibility ? $subjectVisibility->value : (string) $subjectVisibility,
            'grouped' => $persisted['grouped'] ?? false,
            'parent_created' => $persisted['parent_created'] ?? true,
            'occurrence_pending_review' => $persisted['occurrence_pending_review'] ?? false,
        ];
    }

    /**
     * Complete the submission, then record its request-bound share outcome.
     * Both calls run inside the original submission transaction.
     *
     * @param  array<string, mixed>  $state
     */
    private function completeAndRecordSubmissionOutcome(
        Event $event,
        EventSubmission $submission,
        array $state,
        ?User $submitter,
        bool $sessionSubmission,
        bool $autoApproved,
        ?Request $request = null,
        ?EventOccurrence $occurrence = null,
        bool $parentCreated = true,
        bool $groupedSubmission = false,
        bool $occurrencePendingReview = false,
    ): void {
        $this->submissionCompletion->complete(
            eventId: (string) $event->getKey(),
            submissionId: (string) $submission->getKey(),
            state: $state,
            submitterId: $submitter instanceof User ? (string) $submitter->getKey() : null,
            sessionSubmission: $sessionSubmission,
            autoApproved: $autoApproved,
            parentCreated: $parentCreated,
        );

        if ($sessionSubmission) {
            $this->shareTracking->recordOutcome(
                type: ShareOutcomeType::EventSubmission,
                businessKey: ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
                subject: ShareSubject::for(ShareSubjectType::Event->value, (string) $event->getKey(), $event->title),
                actor: $submitter,
                request: ShareRequestContext::fromRequest($request ?? request()),
                metadata: [
                    'submission_id' => $submission->getKey(),
                    'session_submission' => true,
                    'event_occurrence_id' => $occurrence?->getKey(),
                ],
                occurredAt: Carbon::instance($submission->created_at ?? now()),
            );

            return;
        }

        if (! $parentCreated) {
            if ($occurrencePendingReview && $occurrence instanceof EventOccurrence) {
                $this->notifyOccurrenceNeedsReview($event, $occurrence);
            }

            $this->shareTracking->recordOutcome(
                type: ShareOutcomeType::EventSubmission,
                businessKey: ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
                subject: ShareSubject::for(ShareSubjectType::Event->value, (string) $event->getKey(), $event->title),
                actor: $submitter,
                request: ShareRequestContext::fromRequest($request ?? request()),
                metadata: [
                    'submission_id' => $submission->getKey(),
                    'event_occurrence_id' => $occurrence?->getKey(),
                    'grouped_submission' => $groupedSubmission,
                    'occurrence_pending_review' => $occurrencePendingReview,
                ],
                occurredAt: Carbon::instance($submission->created_at ?? now()),
            );

            return;
        }

        $event->refresh();

        $this->shareTracking->recordOutcome(
            type: ShareOutcomeType::EventSubmission,
            businessKey: ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
            subject: ShareSubject::for(ShareSubjectType::Event->value, (string) $event->getKey(), $event->title),
            actor: $submitter,
            request: ShareRequestContext::fromRequest($request ?? request()),
            metadata: [
                'submission_id' => $submission->getKey(),
                'event_occurrence_id' => $occurrence?->getKey(),
                'grouped_submission' => $groupedSubmission,
            ],
            occurredAt: Carbon::instance($submission->created_at ?? now()),
        );
    }

    private function notifyOccurrenceNeedsReview(Event $event, EventOccurrence $occurrence): void
    {
        DB::afterCommit(function () use ($event, $occurrence): void {
            try {
                $moderators = User::role(['moderator', 'super_admin'])->get();

                if ($moderators->isNotEmpty()) {
                    Notification::send($moderators, new EventOccurrenceSubmittedNotification($event, $occurrence));
                }
            } catch (RoleDoesNotExist $exception) {
                Log::warning('Could not notify moderators about a submitted event occurrence.', [
                    'event_id' => (string) $event->getKey(),
                    'event_occurrence_id' => (string) $occurrence->getKey(),
                    'exception' => $exception,
                ]);
            } catch (Throwable $exception) {
                Log::warning('Occurrence submission notification failed after submit.', [
                    'event_id' => (string) $event->getKey(),
                    'event_occurrence_id' => (string) $occurrence->getKey(),
                    'exception' => $exception,
                ]);
            }
        });
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

    private function assertCaptchaIsValid(Request $request, ?string $captchaToken, string $validationKeyPrefix = ''): void
    {
        if (! $this->turnstileVerifier->verify($captchaToken, $request->ip())) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('captcha_token', $validationKeyPrefix) => __('Sila lengkapkan pengesahan keselamatan sebelum menghantar.'),
            ]);
        }
    }
}
