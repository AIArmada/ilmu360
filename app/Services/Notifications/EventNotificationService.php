<?php

namespace App\Services\Notifications;

use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeType;
use App\Enums\EventVisibility;
use App\Enums\NotificationCadence;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Enums\ScheduleState;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\EventCheckin;
use App\Models\Institution;
use App\Models\Registration;
use App\Models\SavedSearch;
use App\Models\Speaker;
use App\Models\User;
use App\Services\EventSearchService;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Notifications\NotificationDispatchData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class EventNotificationService
{
    public function __construct(
        protected NotificationEngine $engine,
        protected EventSearchService $eventSearchService,
        protected MemberPermissionGate $memberPermissionGate,
        protected NotificationMessageRenderer $messageRenderer,
    ) {}

    public function notifySubmissionReceived(Event $event): void
    {
        $recipients = $this->submissionRecipients($event);

        if ($recipients->isEmpty()) {
            return;
        }

        $this->dispatchForUsers($recipients, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: NotificationTrigger::SubmissionReceived,
            titleKey: 'notifications.messages.submission_received.title',
            titleParams: ['title' => $event->title],
            bodyKey: 'notifications.messages.submission_received.body',
            actionUrl: route('dashboard.notifications'),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::Medium,
            fingerprint: 'submission-received:'.$event->id,
            meta: [
                'event_title' => $event->title,
                'event_slug' => $event->slug,
            ],
        ));
    }

    public function notifySubmissionApproved(Event $event): void
    {
        $recipients = $this->submissionRecipients($event);

        if ($recipients->isEmpty()) {
            return;
        }

        $this->dispatchForUsers($recipients, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: NotificationTrigger::SubmissionApproved,
            titleKey: 'notifications.messages.submission_approved.title',
            titleParams: ['title' => $event->title],
            bodyKey: 'notifications.messages.submission_approved.body',
            bodyParams: ['timing' => $this->messageRenderer->eventTimingToken($event)],
            actionUrl: route('events.show', $event),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::High,
            fingerprint: 'submission-approved:'.$event->id,
        ));
    }

    public function notifySubmissionRejected(Event $event, ?string $note = null): void
    {
        $recipients = $this->submissionRecipients($event);

        if ($recipients->isEmpty()) {
            return;
        }

        $bodyKey = filled($note)
            ? 'notifications.messages.submission_rejected.body_with_note'
            : 'notifications.messages.submission_rejected.body';

        $bodyParams = filled($note) ? ['note' => $note] : [];

        $this->dispatchForUsers($recipients, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: NotificationTrigger::SubmissionRejected,
            titleKey: 'notifications.messages.submission_rejected.title',
            titleParams: ['title' => $event->title],
            bodyKey: $bodyKey,
            bodyParams: $bodyParams,
            actionUrl: route('dashboard.notifications'),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::High,
            fingerprint: 'submission-rejected:'.$event->id.':'.sha1((string) $note),
            meta: ['note' => $note],
        ));
    }

    public function notifySubmissionNeedsChanges(Event $event, ?string $note = null): void
    {
        $recipients = $this->submissionRecipients($event);

        if ($recipients->isEmpty()) {
            return;
        }

        $bodyKey = filled($note)
            ? 'notifications.messages.submission_needs_changes.body_with_note'
            : 'notifications.messages.submission_needs_changes.body';

        $bodyParams = filled($note) ? ['note' => $note] : [];

        $this->dispatchForUsers($recipients, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: NotificationTrigger::SubmissionNeedsChanges,
            titleKey: 'notifications.messages.submission_needs_changes.title',
            titleParams: ['title' => $event->title],
            bodyKey: $bodyKey,
            bodyParams: $bodyParams,
            actionUrl: route('dashboard.notifications'),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::High,
            fingerprint: 'submission-needs-changes:'.$event->id.':'.sha1((string) $note),
            meta: ['note' => $note],
        ));
    }

    public function notifySubmissionCancelled(Event $event, ?string $note = null): void
    {
        $recipients = $this->submissionRecipients($event);

        if ($recipients->isEmpty()) {
            return;
        }

        $bodyKey = filled($note)
            ? 'notifications.messages.submission_cancelled.body_with_note'
            : 'notifications.messages.submission_cancelled.body';

        $bodyParams = filled($note) ? ['note' => $note] : [];

        $this->dispatchForUsers($recipients, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: NotificationTrigger::SubmissionCancelled,
            titleKey: 'notifications.messages.submission_cancelled.title',
            titleParams: ['title' => $event->title],
            bodyKey: $bodyKey,
            bodyParams: $bodyParams,
            actionUrl: route('dashboard.notifications'),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::Urgent,
            fingerprint: 'submission-cancelled:'.$event->id.':'.sha1((string) $note),
            meta: ['note' => $note],
        ));
    }

    public function notifySubmissionRemoderated(Event $event, ?string $note = null): void
    {
        $recipients = $this->submissionRecipients($event);

        if ($recipients->isEmpty()) {
            return;
        }

        $bodyKey = filled($note)
            ? 'notifications.messages.submission_remoderated.body_with_note'
            : 'notifications.messages.submission_remoderated.body';

        $bodyParams = filled($note) ? ['note' => $note] : [];

        $this->dispatchForUsers($recipients, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: NotificationTrigger::SubmissionRemoderated,
            titleKey: 'notifications.messages.submission_remoderated.title',
            titleParams: ['title' => $event->title],
            bodyKey: $bodyKey,
            bodyParams: $bodyParams,
            actionUrl: route('dashboard.notifications'),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::Medium,
            fingerprint: 'submission-remoderated:'.$event->id.':'.sha1((string) $note),
            meta: ['note' => $note],
        ));
    }

    public function notifyPublication(Event $event): void
    {
        if (! $this->isPublicFutureEvent($event)) {
            return;
        }

        $this->notifyFollowedContentPublication($event);
        $this->notifySavedSearchMatches($event);
        $this->notifyTrackedEventApproved($event);
    }

    public function notifyTrackedEventCancelled(Event $event, ?string $note = null): void
    {
        $trackedUsers = $this->trackedUsers($event);

        if ($trackedUsers->isEmpty()) {
            return;
        }

        $bodyKey = filled($note)
            ? 'notifications.messages.event_cancelled.body_with_note'
            : 'notifications.messages.event_cancelled.body';

        $bodyParams = ['timing' => $this->messageRenderer->eventTimingToken($event)];

        if (filled($note)) {
            $bodyParams['note'] = $note;
        }

        $this->dispatchForUsers($trackedUsers, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: NotificationTrigger::EventCancelled,
            titleKey: 'notifications.messages.event_cancelled.title',
            titleParams: ['title' => $event->title],
            bodyKey: $bodyKey,
            bodyParams: $bodyParams,
            actionUrl: route('events.show', $event),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::Urgent,
            fingerprint: 'event-cancelled:'.$event->id.':'.sha1((string) $event->updated_at?->toIso8601String()),
            meta: ['note' => $note],
            bypassQuietHours: $this->startsWithinHours($event, 24),
        ));
    }

    public function notifyEventChangeAnnouncement(EventChangeAnnouncement $announcement): void
    {
        $announcement->loadMissing(['event', 'replacementEvent']);
        $event = $announcement->event;
        $recipients = $this->changeAnnouncementRecipients($event);

        if ($recipients->isEmpty()) {
            return;
        }

        $trigger = $this->announcementTrigger($announcement);
        $priority = $this->announcementPriority($announcement);
        $summary = trim((string) $announcement->public_message);

        if ($summary === '') {
            $summary = $announcement->type->label();
        }

        $this->dispatchForUsers($recipients, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: $trigger,
            titleKey: $announcement->type === EventChangeType::Cancelled
                ? 'notifications.messages.event_cancelled.title'
                : 'notifications.messages.event_update.title',
            titleParams: ['title' => $event->title],
            bodyKey: 'notifications.messages.event_change.body',
            bodyParams: ['summary' => $summary],
            actionUrl: route('events.show', $event),
            entityType: Event::class,
            entityId: $event->id,
            priority: $priority,
            fingerprint: 'event-change:'.$announcement->id,
            meta: [
                'event_change_announcement_id' => $announcement->id,
                'event_change_type' => $announcement->type->value,
                'changed_fields' => $announcement->changed_fields ?? [],
                'old_new_summary' => $this->announcementOldNewSummary($announcement),
                'replacement_event_id' => $announcement->replacement_event_id,
            ],
            bypassQuietHours: $priority === NotificationPriority::Urgent,
        ));
    }

    /**
     * @param  array<int, string>  $changedFields
     */
    public function notifyMaterialEventChange(Event $event, array $changedFields): void
    {
        if (! $this->isPublicFutureEvent($event)) {
            return;
        }

        $trigger = $this->materialChangeTrigger($changedFields);

        if (! $trigger instanceof NotificationTrigger) {
            return;
        }

        $trackedUsers = $this->trackedUsers($event);
        $registeredUsers = $this->registeredUsers($event);
        $trackedExcludingRegistered = $trackedUsers
            ->reject(fn (User $user): bool => $registeredUsers->contains('id', $user->id))
            ->values();

        if ($trackedExcludingRegistered->isNotEmpty()) {
            [$bodyKey, $bodyParams] = $this->materialChangeBodyDefinition($event, $trigger);

            $this->dispatchForUsers($trackedExcludingRegistered, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
                user: $user,
                trigger: $trigger,
                titleKey: 'notifications.messages.event_update.title',
                titleParams: ['title' => $event->title],
                bodyKey: $bodyKey,
                bodyParams: $bodyParams,
                actionUrl: route('events.show', $event),
                entityType: Event::class,
                entityId: $event->id,
                priority: $this->materialChangePriority($trigger),
                fingerprint: 'event-update:'.$trigger->value.':'.$event->id.':'.sha1(implode('|', $changedFields).':'.$event->updated_at?->toIso8601String()),
                bypassQuietHours: $this->startsWithinHours($event, 24),
            ));
        }

        if ($registeredUsers->isNotEmpty()) {
            $this->dispatchForUsers($registeredUsers, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
                user: $user,
                trigger: NotificationTrigger::RegistrationEventChanged,
                titleKey: 'notifications.messages.registration_event_changed.title',
                titleParams: ['title' => $event->title],
                bodyKey: 'notifications.messages.registration_event_changed.body',
                bodyParams: ['timing' => $this->messageRenderer->eventTimingToken($event)],
                actionUrl: route('events.show', $event),
                entityType: Event::class,
                entityId: $event->id,
                priority: NotificationPriority::High,
                fingerprint: 'registration-event-changed:'.$event->id.':'.sha1(implode('|', $changedFields).':'.$event->updated_at?->toIso8601String()),
                bypassQuietHours: $this->startsWithinHours($event, 24),
            ));
        }
    }

    public function notifyRegistrationConfirmed(Registration $registration): void
    {
        $user = $registration->user;
        $event = $registration->event;

        if (! $user instanceof User || ! $event instanceof Event) {
            return;
        }

        $this->dispatchForUsers([$user], fn (User $recipient): NotificationDispatchData => $this->buildDispatchData(
            user: $recipient,
            trigger: NotificationTrigger::RegistrationConfirmed,
            titleKey: 'notifications.messages.registration_confirmed.title',
            titleParams: ['title' => $event->title],
            bodyKey: 'notifications.messages.registration_confirmed.body',
            bodyParams: ['timing' => $this->messageRenderer->eventTimingToken($event)],
            actionUrl: route('events.show', $event),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::Medium,
            fingerprint: 'registration-confirmed:'.$registration->id,
        ));
    }

    public function notifyCheckinConfirmed(EventCheckin $checkin): void
    {
        $user = $checkin->user;
        $event = $checkin->event;

        if (! $user instanceof User || ! $event instanceof Event) {
            return;
        }

        $this->dispatchForUsers([$user], fn (User $recipient): NotificationDispatchData => $this->buildDispatchData(
            user: $recipient,
            trigger: NotificationTrigger::CheckinConfirmed,
            titleKey: 'notifications.messages.checkin_confirmed.title',
            titleParams: ['title' => $event->title],
            bodyKey: 'notifications.messages.checkin_confirmed.body',
            actionUrl: route('events.show', $event),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::Medium,
            fingerprint: 'checkin-confirmed:'.$checkin->id,
        ));
    }

    public function dispatchDueReminderNotifications(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now('UTC');

        $this->dispatchReminderWindow($now, NotificationTrigger::Reminder24Hours, $now->addHours(24), $now->addHours(24)->addMinutes(15));
        $this->dispatchReminderWindow($now, NotificationTrigger::Reminder2Hours, $now->addHours(2), $now->addHours(2)->addMinutes(15));
        $this->dispatchReminderWindow($now, NotificationTrigger::CheckinOpen, $now->addHours(2), $now->addHours(2)->addMinutes(15), checkinOpen: true);
    }

    /**
     * @return Collection<int, User>
     */
    protected function submissionRecipients(Event $event): Collection
    {
        $recipients = collect();

        if ($event->submitter_id) {
            $submitter = User::query()->find($event->submitter_id);

            if ($submitter instanceof User) {
                $recipients->push($submitter);
            }
        }

        if ($event->institution) {
            $institutionAdmins = $this->memberPermissionGate
                ->institutionMembersWithPermission($event->institution, 'event.update');
            $recipients = $recipients->merge($institutionAdmins);
        }

        return $recipients
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    protected function changeAnnouncementRecipients(Event $event): Collection
    {
        $event->loadMissing(['institution', 'organizer', 'speakers']);

        $recipients = collect()
            ->merge($this->trackedUsers($event))
            ->merge($this->submissionRecipients($event))
            ->merge($this->memberPermissionGate->eventMembersWithPermission($event, 'event.update'))
            ->merge(User::role(['moderator', 'super_admin'])->get());

        if ($event->institution instanceof Institution) {
            $recipients = $recipients->merge(
                $this->memberPermissionGate->institutionMembersWithPermission($event->institution, 'event.update')
            );
        }

        if ($event->organizer instanceof Institution) {
            $recipients = $recipients->merge(
                $this->memberPermissionGate->institutionMembersWithPermission($event->organizer, 'event.update')
            );
        }

        if ($event->organizer instanceof Speaker) {
            $recipients = $recipients->merge(
                $this->memberPermissionGate->speakerMembersWithPermission($event->organizer, 'event.update')
            );
        }

        foreach ($event->speakers as $speaker) {
            $recipients = $recipients->merge(
                $this->memberPermissionGate->speakerMembersWithPermission($speaker, 'event.update')
            );
        }

        return $recipients
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    protected function trackedUsers(Event $event): Collection
    {
        $registrations = Registration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', 'cancelled')
            ->with('user')
            ->get()
            ->pluck('user');

        return collect()
            ->merge($event->savedBy()->get())
            ->merge($event->goingBy()->get())
            ->merge($registrations)
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    protected function registeredUsers(Event $event): Collection
    {
        return Registration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', 'cancelled')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }

    protected function notifyTrackedEventApproved(Event $event): void
    {
        $trackedUsers = $this->trackedUsers($event);

        if ($trackedUsers->isEmpty()) {
            return;
        }

        $this->dispatchForUsers($trackedUsers, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
            user: $user,
            trigger: NotificationTrigger::EventApproved,
            titleKey: 'notifications.messages.event_approved.title',
            titleParams: ['title' => $event->title],
            bodyKey: 'notifications.messages.event_approved.body',
            bodyParams: ['timing' => $this->messageRenderer->eventTimingToken($event)],
            actionUrl: route('events.show', $event),
            entityType: Event::class,
            entityId: $event->id,
            priority: NotificationPriority::Medium,
            fingerprint: 'event-approved:'.$event->id.':'.sha1((string) $event->updated_at?->toIso8601String()),
        ));
    }

    protected function notifyFollowedContentPublication(Event $event): void
    {
        $event->loadMissing('speakerKeyPeople.speaker.followers');

        $this->dispatchFollowedEntityNotifications(
            trigger: NotificationTrigger::FollowedSpeakerEvent,
            event: $event,
            followables: $event->speakerKeyPeople
                ->pluck('speaker')
                ->filter()
                ->unique('id')
                ->values(),
            labelResolver: static fn (mixed $speaker): ?string => $speaker?->name,
        );

        if ($event->institution !== null) {
            $event->loadMissing('institution.followers');
            $this->dispatchFollowedEntityNotifications(
                trigger: NotificationTrigger::FollowedInstitutionEvent,
                event: $event,
                followables: collect([$event->institution]),
                labelResolver: static fn (mixed $institution): ?string => $institution?->name,
            );
        }

        $this->dispatchFollowedEntityNotifications(
            trigger: NotificationTrigger::FollowedSeriesEvent,
            event: $event,
            followables: $event->series()->with('followers')->get(),
            labelResolver: static fn (mixed $series): ?string => $series?->title,
        );

        $this->dispatchFollowedEntityNotifications(
            trigger: NotificationTrigger::FollowedReferenceEvent,
            event: $event,
            followables: $event->references()->with('followers')->get(),
            labelResolver: static fn (mixed $reference): ?string => $reference?->title,
        );
    }

    protected function notifySavedSearchMatches(Event $event): void
    {
        $matchesByUser = [];

        SavedSearch::query()
            ->where('notify', '!=', NotificationCadence::Off->value)
            ->with('user')
            ->cursor()
            ->each(function (SavedSearch $savedSearch) use ($event, &$matchesByUser): void {
                $user = $savedSearch->user;

                if (! $user instanceof User) {
                    return;
                }

                if (! $this->savedSearchMatchesEvent($savedSearch, $event)) {
                    return;
                }

                $matchesByUser[$user->id] ??= [
                    'user' => $user,
                    'searches' => [],
                ];
                $matchesByUser[$user->id]['searches'][] = $savedSearch;
            });

        foreach ($matchesByUser as $matchData) {
            $user = $matchData['user'];
            /** @var array<int, SavedSearch> $searches */
            $searches = $matchData['searches'];
            $searchNames = collect($searches)->pluck('name')->values()->all();

            $this->dispatchForUsers([$user], fn (User $recipient): NotificationDispatchData => $this->buildDispatchData(
                user: $recipient,
                trigger: NotificationTrigger::SavedSearchMatch,
                titleKey: 'notifications.messages.saved_search_match.title',
                titleParams: ['title' => $event->title],
                bodyKey: 'notifications.messages.saved_search_match.body',
                bodyParams: [
                    'searches' => implode(', ', array_slice($searchNames, 0, 3)),
                ],
                actionUrl: route('events.show', $event),
                entityType: Event::class,
                entityId: $event->id,
                priority: NotificationPriority::Low,
                forcedCadence: $this->fastestSavedSearchCadence($searches),
                fingerprint: 'saved-search-match:'.$event->id.':'.$user->id,
                meta: [
                    'saved_search_ids' => collect($searches)->pluck('id')->all(),
                    'saved_search_names' => $searchNames,
                ],
            ));
        }
    }

    /**
     * @param  Collection<int, mixed>  $followables
     * @param  callable(mixed): ?string  $labelResolver
     */
    protected function dispatchFollowedEntityNotifications(
        NotificationTrigger $trigger,
        Event $event,
        Collection $followables,
        callable $labelResolver,
    ): void {
        $users = [];

        foreach ($followables as $followable) {
            $labels = [];

            if (! method_exists($followable, 'followers')) {
                continue;
            }

            /** @var Collection<int, User> $followers */
            $followers = $followable->followers()->get();

            foreach ($followers as $follower) {
                $users[$follower->id]['user'] = $follower;
                $label = $labelResolver($followable);

                if (is_string($label) && $label !== '') {
                    $users[$follower->id]['labels'][$label] = $label;
                }
            }
        }

        foreach ($users as $match) {
            /** @var User $user */
            $user = $match['user'];
            $labels = array_values($match['labels'] ?? []);

            $this->dispatchForUsers([$user], fn (User $recipient): NotificationDispatchData => $this->buildDispatchData(
                user: $recipient,
                trigger: $trigger,
                titleKey: 'notifications.messages.followed_content.title',
                titleParams: ['title' => $event->title],
                bodyKey: 'notifications.messages.followed_content.body',
                bodyParams: [
                    'matches' => implode(', ', array_slice($labels, 0, 3)),
                    'timing' => $this->messageRenderer->eventTimingToken($event),
                ],
                actionUrl: route('events.show', $event),
                entityType: Event::class,
                entityId: $event->id,
                priority: NotificationPriority::Low,
                fingerprint: 'followed-content:'.$trigger->value.':'.$event->id.':'.$user->id,
                meta: ['matched_entities' => $labels],
            ));
        }
    }

    protected function savedSearchMatchesEvent(SavedSearch $savedSearch, Event $event): bool
    {
        $filters = is_array($savedSearch->filters) ? $savedSearch->filters : [];

        if ($savedSearch->lat !== null && $savedSearch->lng !== null && $savedSearch->radius_km !== null) {
            $results = $this->eventSearchService->searchNearby(
                lat: $savedSearch->lat,
                lng: $savedSearch->lng,
                radiusKm: $savedSearch->radius_km,
                filters: $filters,
                perPage: 50,
            );

            return collect($results->items())->contains(fn (Event $candidate): bool => $candidate->id === $event->id);
        }

        $results = $this->eventSearchService->search(
            query: $savedSearch->query,
            filters: $filters,
            perPage: 50,
        );

        return collect($results->items())->contains(fn (Event $candidate): bool => $candidate->id === $event->id);
    }

    /**
     * @param  array<int, SavedSearch>  $savedSearches
     */
    protected function fastestSavedSearchCadence(array $savedSearches): NotificationCadence
    {
        $cadences = collect($savedSearches)
            ->map(fn (SavedSearch $savedSearch): NotificationCadence => NotificationCadence::tryFrom((string) $savedSearch->notify) ?? NotificationCadence::Daily)
            ->values();

        if ($cadences->contains(NotificationCadence::Instant)) {
            return NotificationCadence::Instant;
        }

        if ($cadences->contains(NotificationCadence::Daily)) {
            return NotificationCadence::Daily;
        }

        if ($cadences->contains(NotificationCadence::Weekly)) {
            return NotificationCadence::Weekly;
        }

        return NotificationCadence::Off;
    }

    /**
     * @param  array<int, string>  $changedFields
     */
    protected function materialChangeTrigger(array $changedFields): ?NotificationTrigger
    {
        if (in_array('venue_id', $changedFields, true) || in_array('space_id', $changedFields, true)) {
            return NotificationTrigger::EventVenueChanged;
        }

        if (array_intersect($changedFields, ['starts_at', 'ends_at', 'timezone', 'title'])) {
            return NotificationTrigger::EventScheduleChanged;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function materialChangeBodyDefinition(Event $event, NotificationTrigger $trigger): array
    {
        return match ($trigger) {
            NotificationTrigger::EventVenueChanged => [
                'notifications.messages.event_venue_changed.body',
                ['timing' => $this->messageRenderer->eventTimingToken($event)],
            ],
            default => [
                'notifications.messages.event_schedule_changed.body',
                ['timing' => $this->messageRenderer->eventTimingToken($event)],
            ],
        };
    }

    protected function materialChangePriority(NotificationTrigger $trigger): NotificationPriority
    {
        return $trigger === NotificationTrigger::EventVenueChanged
            ? NotificationPriority::High
            : NotificationPriority::High;
    }

    protected function announcementTrigger(EventChangeAnnouncement $announcement): NotificationTrigger
    {
        return match ($announcement->type) {
            EventChangeType::Cancelled => NotificationTrigger::EventCancelled,
            EventChangeType::Postponed,
            EventChangeType::RescheduledEarlier,
            EventChangeType::RescheduledLater,
            EventChangeType::ScheduleChanged => NotificationTrigger::EventScheduleChanged,
            EventChangeType::LocationChanged => NotificationTrigger::EventVenueChanged,
            EventChangeType::ReplacementLinked => NotificationTrigger::EventReplacementLinked,
            default => NotificationTrigger::EventDetailsChanged,
        };
    }

    protected function announcementPriority(EventChangeAnnouncement $announcement): NotificationPriority
    {
        if ($announcement->severity === EventChangeSeverity::Urgent) {
            return NotificationPriority::Urgent;
        }

        if ($announcement->severity === EventChangeSeverity::High) {
            return NotificationPriority::High;
        }

        return NotificationPriority::Medium;
    }

    /**
     * @return array<string, mixed>
     */
    protected function announcementOldNewSummary(EventChangeAnnouncement $announcement): array
    {
        return [
            'before' => $announcement->before_snapshot,
            'after' => $announcement->after_snapshot,
        ];
    }

    protected function dispatchReminderWindow(
        CarbonImmutable $now,
        NotificationTrigger $trigger,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        bool $checkinOpen = false,
    ): void {
        $events = Event::query()
            ->where('is_active', true)
            ->whereIn('status', Event::ENGAGEABLE_STATUSES)
            ->where('visibility', EventVisibility::Public)
            ->where(fn ($query) => $query
                ->whereNull('schedule_state')
                ->orWhere('schedule_state', '!=', ScheduleState::Postponed->value))
            ->whereBetween('starts_at', [
                $windowStart->utc()->toDateTimeString(),
                $windowEnd->utc()->toDateTimeString(),
            ])
            ->get();

        foreach ($events as $event) {
            $users = $this->trackedReminderUsers($event, $checkinOpen);

            if ($users->isEmpty()) {
                continue;
            }

            $titleKey = match ($trigger) {
                NotificationTrigger::Reminder24Hours => 'notifications.messages.reminder_24_hours.title',
                NotificationTrigger::Reminder2Hours => 'notifications.messages.reminder_2_hours.title',
                default => 'notifications.messages.checkin_open.title',
            };

            $bodyKey = match ($trigger) {
                NotificationTrigger::Reminder24Hours => 'notifications.messages.reminder_24_hours.body',
                NotificationTrigger::Reminder2Hours => 'notifications.messages.reminder_2_hours.body',
                default => 'notifications.messages.checkin_open.body',
            };

            $this->dispatchForUsers($users, fn (User $user): NotificationDispatchData => $this->buildDispatchData(
                user: $user,
                trigger: $trigger,
                titleKey: $titleKey,
                titleParams: ['title' => $event->title],
                bodyKey: $bodyKey,
                bodyParams: ['timing' => $this->messageRenderer->eventTimingToken($event)],
                actionUrl: route('events.show', $event),
                entityType: Event::class,
                entityId: $event->id,
                priority: $trigger === NotificationTrigger::Reminder24Hours ? NotificationPriority::Medium : NotificationPriority::Urgent,
                fingerprint: 'reminder:'.$trigger->value.':'.$event->id.':'.$event->starts_at?->timestamp,
                bypassQuietHours: $trigger !== NotificationTrigger::Reminder24Hours,
            ));
        }
    }

    /**
     * @return Collection<int, User>
     */
    protected function trackedReminderUsers(Event $event, bool $checkinOpen = false): Collection
    {
        return collect()
            ->merge($event->goingBy()->get())
            ->merge($this->registeredUsers($event))
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }

    /**
     * @param  iterable<int, User>  $users
     * @param  callable(User): NotificationDispatchData  $builder
     */
    protected function dispatchForUsers(iterable $users, callable $builder): void
    {
        collect($users)
            ->unique('id')
            ->values()
            ->each(function (User $user) use ($builder): void {
                $data = $this->withUserLocale($user, fn (): NotificationDispatchData => $builder($user));

                $this->engine->dispatchToUser($user, $data);
            });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withUserLocale(User $user, callable $callback): mixed
    {
        $originalLocale = app()->getLocale();
        app()->setLocale($user->preferredLocale());

        try {
            return $callback();
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    /**
     * @param  array<string, mixed>  $titleParams
     * @param  array<string, mixed>  $bodyParams
     * @param  array<string, mixed>  $meta
     */
    protected function buildDispatchData(
        User $user,
        NotificationTrigger $trigger,
        string $titleKey,
        array $titleParams = [],
        string $bodyKey = 'notifications.messages.submission_received.body',
        array $bodyParams = [],
        ?string $actionUrl = null,
        ?string $entityType = null,
        ?string $entityId = null,
        NotificationPriority $priority = NotificationPriority::Medium,
        ?NotificationCadence $forcedCadence = null,
        ?string $fingerprint = null,
        array $meta = [],
        ?CarbonImmutable $occurredAt = null,
        bool $bypassQuietHours = false,
    ): NotificationDispatchData {
        $render = [
            'title' => [
                'key' => $titleKey,
                'params' => $titleParams,
            ],
            'body' => [
                'key' => $bodyKey,
                'params' => $bodyParams,
            ],
        ];

        return new NotificationDispatchData(
            trigger: $trigger,
            title: $this->messageRenderer->renderDefinition($render['title'], $user),
            body: $this->messageRenderer->renderDefinition($render['body'], $user),
            actionUrl: $actionUrl,
            entityType: $entityType,
            entityId: $entityId,
            priority: $priority,
            forcedCadence: $forcedCadence,
            fingerprint: $fingerprint,
            meta: $meta,
            occurredAt: $occurredAt,
            bypassQuietHours: $bypassQuietHours,
            render: $render,
        );
    }

    protected function isPublicFutureEvent(Event $event): bool
    {
        return $event->is_active
            && (string) $event->status === 'approved'
            && $event->visibility === EventVisibility::Public
            && $event->starts_at !== null
            && $event->starts_at->isFuture();
    }

    protected function startsWithinHours(Event $event, int $hours): bool
    {
        return $event->starts_at !== null
            && $event->starts_at->isBetween(now(), now()->addHours($hours));
    }
}
