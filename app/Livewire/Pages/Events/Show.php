<?php

namespace App\Livewire\Pages\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Response;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Models\EventRegistration;
use App\Actions\Events\MarkEventGoingAction;
use App\Actions\Events\RecordEventCheckInAction;
use App\Actions\Events\RemoveEventGoingAction;
use App\Actions\Events\ResolveEventCheckInStateAction;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\EventCheckin;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\ShareTrackingService;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\EventStatus;
use App\States\EventStatus\Pending;
use App\Support\Auth\IntendedRedirect;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Layout('layouts.app')]
#[Title('Event Details')]
class Show extends Component
{
    public Event $event;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public bool $isSaved = false;

    public bool $isGoing = false;

    public bool $isCheckedIn = false;

    public bool $hasPass = false;

    public ?string $passId = null;

    public int $goingCount = 0;

    public int $registrationsCount = 0;

    public function mount(Event $event): void
    {
        $isViewable = $event->isPubliclyReachable();
        $isOwner = $this->isEventOwner($event);

        // Owners can always view their own events (drafts, pending, approved, etc)
        if ($isOwner) {
            // Allow access
        }
        // Public events: anyone can view if active and approved/pending
        elseif ($isViewable && $event->visibility === EventVisibility::Public) {
            // Allow access
        }
        // Unlisted events: anyone with link can view if active and approved/pending
        elseif ($isViewable && $event->visibility === EventVisibility::Unlisted) {
            // Allow access
        }
        // All other cases: 404
        else {
            abort(404);
        }

        OwnerContext::withOwner(null, function () use ($event): void {
            $publicScheduleScope = function (Relation $query) use ($event): void {
                if ($this->isEventOwner($event)) {
                    return;
                }

                $query
                    ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                    ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES);
            };

            $publicLocationScope = function (Relation $query) use ($event): void {
                if ($this->isEventOwner($event)) {
                    return;
                }

                $query
                    ->where('status', 'active')
                    ->where('visibility', 'public');
            };

            $publicTicketScope = function (Relation $relation): void {
                $relation->getQuery()
                    ->where('status', 'active')
                    ->where('visibility', 'public')
                    ->orderBy('sort_order')
                    ->orderBy('name');
            };

            $publicSeatMapScope = function (Relation $relation): void {
                $relation->getQuery()
                    ->where('status', 'active')
                    ->orderBy('name');
            };

            $publicLinkScope = function (Relation $relation): void {
                $relation->getQuery()
                    ->where('visibility', 'public')
                    ->orderBy('sort_order')
                    ->orderBy('label');
            };

            $publicMaterialScope = function (Relation $relation): void {
                $relation->getQuery()
                    ->where('visibility', 'public')
                    ->whereNotNull('url')
                    ->orderBy('sort_order')
                    ->orderBy('title');
            };
            $capacityRegistrationScope = static function (Builder $query): void {
                $query->whereIn(
                    'status',
                    config('events.lifecycle.registration.capacity_blocking_statuses', EventRegistration::CAPACITY_BLOCKING_STATUSES),
                );
            };

            $event->loadCount(['registrations' => $capacityRegistrationScope]);
            $event->loadSum(['registrations' => $capacityRegistrationScope], 'total_participants');
            $this->registrationsCount = $event->registrations_count;
            $event->load([
                'media',
                'primaryOrganizerInvolvement.involveable',
                'institution.media',
                'institution.addresses.country',
                'institution.addresses.state',
                'institution.addresses.city',
                'institution.addresses.areaAssignments.area',
                'institution.contactMethods',
                'venue.media',
                'venue.addresses.country',
                'venue.addresses.state',
                'venue.addresses.city',
                'venue.addresses.areaAssignments.area',
                'venue.contactMethods',
                'persons.media',
                'persons.titleAssignments.title.category',
                'keyPeople.person.media',
                'keyPeople.person.titleAssignments.title.category',
                'classifications',
                'donationChannel.media',
                'accessPolicy',
                'series',
                'references' => function (Relation $query) use ($event): void {
                    if (! $this->isEventOwner($event)) {
                        Reference::applyPublicVisibility($query->getQuery());
                    }

                    $query->with('media');
                },
                'links' => $publicLinkScope,
                'materials' => $publicMaterialScope,
                'audiences',
                'audienceProfiles',
                'locations' => $publicLocationScope,
                'locations.venueSpace',
                'primaryLocation' => $publicLocationScope,
                'primaryLocation.venueSpace',
                'primaryOccurrence' => $publicScheduleScope,
                'sessions' => function (Relation $query) use ($publicScheduleScope, $capacityRegistrationScope): void {
                    $publicScheduleScope($query);
                    $query
                        ->whereNull('event_occurrence_id')
                        ->withCount(['registrations' => $capacityRegistrationScope])
                        ->withSum(['registrations' => $capacityRegistrationScope], 'total_participants')
                        ->orderBy('sort_order')
                        ->orderBy('starts_at')
                        ->orderBy('created_at')
                        ->orderBy('id');
                },
                'occurrences' => function (Relation $query) use ($publicScheduleScope, $capacityRegistrationScope): void {
                    $publicScheduleScope($query);
                    $query
                        ->withCount(['registrations' => $capacityRegistrationScope])
                        ->withSum(['registrations' => $capacityRegistrationScope], 'total_participants');
                },
                'occurrences.media',
                'occurrences.sessions' => function (Relation $query) use ($publicScheduleScope, $capacityRegistrationScope): void {
                    $publicScheduleScope($query);
                    $query
                        ->withCount(['registrations' => $capacityRegistrationScope])
                        ->withSum(['registrations' => $capacityRegistrationScope], 'total_participants')
                        ->orderBy('sort_order')
                        ->orderBy('starts_at')
                        ->orderBy('created_at')
                        ->orderBy('id');
                },
                'occurrences.locations' => $publicLocationScope,
                'occurrences.locations.venueSpace',
                'occurrences.accessPolicies',
                'occurrences.ticketTypes' => $publicTicketScope,
                'occurrences.ticketTypes.seatingOptions.section',
                'occurrences.ticketTypes.inventoryLevels',
                'occurrences.seatMaps' => $publicSeatMapScope,
                'occurrences.seatMaps.sections',
                'occurrences.links' => $publicLinkScope,
                'occurrences.materials' => $publicMaterialScope,
                'occurrences.sessions.media',
                'occurrences.sessions.involvements' => function (Relation $query) use ($event, $publicScheduleScope): void {
                    $publicScheduleScope($query);

                    if (! $this->isEventOwner($event)) {
                        $query->where('status', 'active');
                    }
                },
                'occurrences.sessions.involvements.involveable',
                'occurrences.sessions.timeExpressions',
                'occurrences.sessions.locations' => $publicLocationScope,
                'occurrences.sessions.locations.venueSpace',
                'occurrences.sessions.accessPolicies',
                'occurrences.sessions.ticketTypes' => $publicTicketScope,
                'occurrences.sessions.ticketTypes.seatingOptions.section',
                'occurrences.sessions.ticketTypes.inventoryLevels',
                'occurrences.sessions.seatMaps' => $publicSeatMapScope,
                'occurrences.sessions.seatMaps.sections',
                'occurrences.sessions.links' => $publicLinkScope,
                'occurrences.sessions.materials' => $publicMaterialScope,
                'occurrences.timeExpressions',
                'sessions.media',
                'sessions.involvements' => function (Relation $query) use ($event, $publicScheduleScope): void {
                    $publicScheduleScope($query);

                    if (! $this->isEventOwner($event)) {
                        $query->where('status', 'active');
                    }
                },
                'sessions.involvements.involveable',
                'sessions.timeExpressions',
                'sessions.locations' => $publicLocationScope,
                'sessions.locations.venueSpace',
                'sessions.accessPolicies',
                'sessions.ticketTypes' => $publicTicketScope,
                'sessions.ticketTypes.seatingOptions.section',
                'sessions.ticketTypes.inventoryLevels',
                'sessions.seatMaps' => $publicSeatMapScope,
                'sessions.seatMaps.sections',
                'sessions.links' => $publicLinkScope,
                'sessions.materials' => $publicMaterialScope,
                'languages',
                'timeExpressions',
                'ticketTypes' => $publicTicketScope,
                'ticketTypes.seatingOptions.section',
                'ticketTypes.inventoryLevels',
                'seatMaps' => $publicSeatMapScope,
                'seatMaps.sections',
                'latestPublishedChangeAnnouncement.replacementEvent.media',
                'latestPublishedChangeAnnouncement.replacementEvent.institution.media',
                'latestPublishedChangeAnnouncement.replacementEvent.persons.media',
                'latestPublishedReplacementAnnouncement.replacementEvent.media',
                'latestPublishedReplacementAnnouncement.replacementEvent.institution.media',
                'latestPublishedReplacementAnnouncement.replacementEvent.persons.media',
                'publishedChangeAnnouncements.replacementEvent',
            ]);

            if ($involveable = $event->primaryOrganizerInvolvement?->involveable) {
                if ($involveable instanceof Institution) {
                    $involveable->loadMissing(['media', 'contactMethods']);
                } elseif ($involveable instanceof Person) {
                    $involveable->loadMissing(['media']);
                }
            }
        });

        $this->event = $event;
        $this->syncEngagementStates();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function calendarLinks(): array
    {
        if ($this->eventActionsDisabled()) {
            return [];
        }

        return app(CalendarService::class)->getAllCalendarLinks($this->event);
    }

    /**
     * @return array<int, array{url: string, thumb: string, alt: string}>
     */
    #[Computed]
    public function galleryImages(): array
    {
        $images = [];
        $imageCounter = 1;

        foreach ($this->event->getMedia('gallery') as $galleryMedia) {
            $images[] = $this->buildGalleryImagePayload(
                $galleryMedia,
                __('Photo :number', ['number' => $imageCounter++])
            );
        }

        return $images;
    }

    #[Computed]
    public function metaRobots(): string
    {
        return $this->isSearchIndexable($this->event) ? 'index, follow' : 'noindex, nofollow';
    }

    #[Computed]
    public function activeChangeNotice(): ?EventChangeAnnouncement
    {
        $notice = $this->event->latestPublishedChangeAnnouncement;

        return $notice instanceof EventChangeAnnouncement ? $notice : null;
    }

    /**
     * Resolve replacement chains to the latest event users should inspect.
     */
    #[Computed]
    public function replacementEvent(): ?Event
    {
        return $this->event->replacementLinkTarget();
    }

    public function replacementLinkTargetForAnnouncement(EventChangeAnnouncement $announcement): ?Event
    {
        return $this->event->replacementLinkTargetForAnnouncement($announcement);
    }

    #[Computed]
    public function isPostponedWithoutConfirmedTime(): bool
    {
        return $this->event->primaryOccurrence && in_array((string) $this->event->primaryOccurrence->status, ['postponed', 'rescheduled'], true);
    }

    #[Computed]
    public function eventActionsDisabled(): bool
    {
        return $this->isCancelledStatus($this->event) || $this->isPostponedWithoutConfirmedTime();
    }

    public function registrationMode(): RegistrationMode
    {
        return $this->event->resolvedRegistrationMode();
    }

    /**
     * @return Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, EventKeyPerson>>
     */
    #[Computed]
    public function keyPeopleByRole(): Collection
    {
        return collect($this->event->keyPeople
            ->filter(fn (EventKeyPerson $keyPerson): bool => $keyPerson->role_code !== EventKeyPersonRole::Speaker->value && $keyPerson->visibility === 'public')
            ->groupBy(fn (EventKeyPerson $keyPerson): string => (string) $keyPerson->role_code)
            ->sortKeys()
            ->all());
    }

    /**
     * Determine the event's temporal status for display purposes.
     */
    #[Computed]
    public function eventTimeStatus(): string
    {
        $now = now($this->event->timezone ?: 'Asia/Kuala_Lumpur');
        $startsAt = $this->event->starts_at;
        $endsAt = $this->effectiveEndsAt();

        if (! $startsAt instanceof CarbonInterface) {
            return 'upcoming';
        }

        if ($endsAt instanceof CarbonInterface && $now->greaterThan($endsAt)) {
            return 'past';
        }

        if ($startsAt->isPast() && (! $endsAt instanceof CarbonInterface || $now->lessThanOrEqualTo($endsAt))) {
            return 'happening_now';
        }

        if ($startsAt->isFuture() && $startsAt->diffInHours($now) <= 24) {
            return 'starting_soon';
        }

        return 'upcoming';
    }

    protected function effectiveEndsAt(): ?CarbonInterface
    {
        $endsAt = $this->event->ends_at;

        if ($endsAt instanceof CarbonInterface) {
            return $endsAt;
        }

        $startsAt = $this->event->starts_at;

        if (! $startsAt instanceof CarbonInterface) {
            return null;
        }

        return $startsAt->copy()->addHours(2);
    }

    /**
     * Render the event description as safe HTML.
     */
    #[Computed]
    public function descriptionHtml(): string
    {
        $description = $this->event->description;

        if (is_array($description)) {
            $html = $description['html'] ?? null;

            if (is_string($html) && $this->hasRenderableHtmlContent($html)) {
                return $html;
            }
        }

        $text = trim($this->event->description_text);

        return $text !== '' ? nl2br(e($text)) : '';
    }

    #[Computed]
    public function hasAboutContent(): bool
    {
        return trim((string) $this->event->summary) !== ''
            || $this->descriptionHtml() !== ''
            || $this->event->classifications()->exists();
    }

    /**
     * Find a small set of public events that share the event's institution or
     * taxonomy terms. This keeps recommendations tied to actual event graph
     * relationships instead of using an opaque search-only similarity score.
     *
     * @return Collection<int, Event>
     */
    #[Computed]
    public function relatedEvents(): Collection
    {
        $institutionId = $this->event->institution_id;
        $termIds = $this->event->classifications
            ->pluck('event_term_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if (! filled($institutionId) && $termIds->isEmpty()) {
            return collect();
        }

        $query = Event::query()
            ->whereKeyNot($this->event->getKey())
            ->where('status', 'approved')
            ->where('visibility', EventVisibility::Public->value)
            ->whereNotNull('published_at')
            ->whereHas('occurrences')
            ->with([
                'media',
                'institution',
                'venue',
                'primaryOccurrence',
            ])
            ->when(
                filled($institutionId),
                fn (Builder $query): Builder => $query->orderByRaw(
                    'CASE WHEN institution_id = ? THEN 0 ELSE 1 END',
                    [$institutionId],
                ),
            )
            ->orderBy('starts_at')
            ->limit(6);

        $query->where(function (Builder $query) use ($institutionId, $termIds): void {
            if (filled($institutionId)) {
                $query->where('institution_id', $institutionId);
            }

            if ($termIds->isNotEmpty()) {
                $query->orWhereHas(
                    'classifications',
                    fn (Builder $classificationQuery): Builder => $classificationQuery->whereIn('event_term_id', $termIds->all()),
                );
            }
        });

        return $query->get();
    }

    private function hasRenderableHtmlContent(string $html): bool
    {
        $plainText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainText = str_replace("\u{00A0}", ' ', $plainText);
        $plainText = preg_replace('/\s+/u', '', $plainText) ?? trim($plainText);

        if ($plainText !== '') {
            return true;
        }

        return preg_match('/<(img|picture|figure|iframe|video|audio|embed|object|svg|canvas)\b/i', $html) === 1;
    }

    /**
     * @return array{whatsapp: string, telegram: string, threads: string, facebook: string, x: string, instagram: string, tiktok: string, email: string}
     */
    #[Computed]
    public function shareLinks(): array
    {
        /** @var array<string, string> $platformLinks */
        $platformLinks = app(ShareTrackingService::class)->redirectLinks(
            route('events.show', $this->event),
            trim($this->event->title.' - '.config('app.name')),
            $this->event->title,
        );

        return $platformLinks;
    }

    public function toggleSave(): void
    {
        $this->toggleEngagement('savedEvents', 'isSaved', 'saves_count');
    }

    public function toggleGoing(): void
    {
        $this->toggleEngagement('goingEvents', 'isGoing', 'going_count', 'goingCount', requiresActiveEvent: true);
    }

    public function checkIn(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(IntendedRedirect::loginUrl(route('events.show', $this->event)), navigate: true);

            return;
        }

        if ($this->eventActionsDisabled()) {
            FilamentNotification::make()
                ->title(__('Tindakan ini ditutup kerana jadual majlis belum tersedia.'))
                ->warning()
                ->send();

            return;
        }

        $state = $this->resolveCheckInState($user);

        if (! $state['available']) {
            if (filled($state['reason'])) {
                FilamentNotification::make()
                    ->title((string) $state['reason'])
                    ->warning()
                    ->send();
            }

            return;
        }

        $checkinResult = app(RecordEventCheckInAction::class)->handle(
            $this->event,
            $user,
            $state['registration_id'],
            $state['method'],
            request(),
        );

        if ($checkinResult['status'] === 'duplicate') {
            $this->isCheckedIn = true;

            FilamentNotification::make()
                ->title(__('Anda sudah check-in untuk majlis ini.'))
                ->success()
                ->send();

            return;
        }

        $this->isCheckedIn = true;
        unset($this->checkInState);

        FilamentNotification::make()
            ->title(__('Check-in berjaya direkodkan.'))
            ->success()
            ->send();
    }

    /**
     * @return array{available: bool, reason: string|null}
     */
    #[Computed]
    public function checkInState(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [
                'available' => false,
                'reason' => __('Log masuk untuk check-in.'),
            ];
        }

        $state = $this->resolveCheckInState($user);

        return [
            'available' => $state['available'],
            'reason' => $state['reason'],
        ];
    }

    protected function toggleEngagement(string $relation, string $stateProperty, string $countColumn, ?string $countProperty = null, bool $requiresActiveEvent = false): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(IntendedRedirect::loginUrl(route('events.show', $this->event)), navigate: true);

            return;
        }

        if (! $this->isEngagementStatus($this->event) || $this->event->visibility !== EventVisibility::Public) {
            abort(403);
        }

        if ($requiresActiveEvent && $this->eventActionsDisabled()) {
            FilamentNotification::make()
                ->title(__('Tindakan ini ditutup kerana jadual majlis belum tersedia.'))
                ->warning()
                ->send();

            return;
        }

        if ($this->{$stateProperty}) {
            $result = match ($relation) {
                'savedEvents' => (function () use ($user): array {
                    app(EngagementManager::class)->removeBookmark($user, $this->event);

                    return ['saves_count' => app(EngagementCounterService::class)->value($this->event, 'bookmarks')];
                })(),
                'goingEvents' => app(RemoveEventGoingAction::class)->handle((string) $this->event->getKey(), $user),
                default => ['deleted' => false, $countColumn => max(0, (int) ($this->event->{$countColumn} ?? 0))],
            };

            $updatedCount = max(0, (int) ($result[$countColumn] ?? 0));

            if ($countProperty) {
                $this->{$countProperty} = $updatedCount;
            }

            $this->{$stateProperty} = false;
        } else {
            $result = match ($relation) {
                'savedEvents' => (function () use ($user): array {
                    app(EngagementManager::class)->bookmark($user, $this->event);

                    return ['saves_count' => app(EngagementCounterService::class)->value($this->event, 'bookmarks')];
                })(),
                'goingEvents' => app(MarkEventGoingAction::class)->handle($this->event, $user),
                default => ['status' => 'conflict', $countColumn => (int) ($this->event->{$countColumn} ?? 0)],
            };

            $updatedCount = max(0, (int) ($result[$countColumn] ?? 0));

            if ($countProperty) {
                $this->{$countProperty} = $updatedCount;
            }

            $this->{$stateProperty} = true;
        }
    }

    protected function recordEngagementOutcome(string $relation, User $user): void
    {
        $type = match ($relation) {
            'savedEvents' => DawahShareOutcomeType::EventSave,
            'goingEvents' => DawahShareOutcomeType::EventGoing,
            default => null,
        };

        if (! $type instanceof DawahShareOutcomeType) {
            return;
        }

        app(ShareTrackingService::class)->recordOutcome(
            type: $type,
            outcomeKey: $type->value.':user:'.$user->id.':event:'.$this->event->id,
            subject: $this->event,
            actor: $user,
            request: request(),
            metadata: [
                'event_id' => $this->event->id,
            ],
        );
    }

    protected function syncEngagementStates(): void
    {
        $this->goingCount = Response::query()
            ->where('respondable_type', $this->event->getMorphClass())
            ->where('respondable_id', $this->event->getKey())
            ->where('response_type', 'going')
            ->active()
            ->count();

        $user = auth()->user();

        if (! $user instanceof User) {
            $this->isSaved = false;
            $this->isGoing = false;
            $this->isCheckedIn = false;

            return;
        }

        $this->isSaved = Bookmark::forBookmarker($user)->forBookmarkable($this->event)->active()->exists();
        $this->isGoing = $user->goingEvents()->whereKey($this->event->getKey())->exists();
        $this->isCheckedIn = EventCheckin::query()
            ->where('event_id', $this->event->id)
            ->where('attendee_id', $user->id)
            ->exists();
        $pass = $this->event->passes()
            ->whereHas('holder', fn ($q) => $q->where('holder_id', $user->id))
            ->first();
        $this->hasPass = $pass !== null;
        $this->passId = $pass?->id;
    }

    /**
     * @return array{url: string, thumb: string, alt: string}
     */
    protected function buildGalleryImagePayload(Media $media, string $fallbackAlt): array
    {
        $fullImageUrl = $media->getAvailableUrl(['thumb']);
        $thumbnailUrl = $media->getAvailableUrl(['thumb']);

        return [
            'url' => $fullImageUrl !== '' ? $fullImageUrl : $media->getUrl(),
            'thumb' => $thumbnailUrl !== '' ? $thumbnailUrl : ($fullImageUrl !== '' ? $fullImageUrl : $media->getUrl()),
            'alt' => filled($media->name) ? (string) $media->name : $fallbackAlt,
        ];
    }

    public function render(): View
    {
        return view('livewire.pages.events.show');
    }

    protected function isPubliclyVisibleStatus(Event $event): bool
    {
        $status = $event->status;

        if ($status instanceof EventStatus) {
            return $status->equals(Approved::class)
                || $status->equals(Pending::class)
                || $status->equals(Cancelled::class);
        }

        return in_array((string) $status, Event::PUBLIC_STATUSES, true);
    }

    protected function isEngagementStatus(Event $event): bool
    {
        $status = $event->status;

        if ($status instanceof EventStatus) {
            return $status->equals(Approved::class) || $status->equals(Pending::class);
        }

        return in_array((string) $status, Event::ENGAGEABLE_STATUSES, true);
    }

    protected function isSearchIndexable(Event $event): bool
    {
        if ($event->published_at === null || $event->visibility !== EventVisibility::Public || ! $event->hasOccurrences()) {
            return false;
        }

        $status = $event->status;

        if ($status instanceof EventStatus) {
            return $status->equals(Approved::class) || $status->equals(Cancelled::class);
        }

        return in_array((string) $status, ['approved', 'cancelled'], true);
    }

    protected function isCancelledStatus(Event $event): bool
    {
        $status = $event->status;

        if ($status instanceof EventStatus) {
            return $status->equals(Cancelled::class);
        }

        return (string) $status === 'cancelled';
    }

    /**
     * Determine whether the currently authenticated user submitted the event.
     * Event ownership itself is resolved by the package owner policy.
     */
    protected function isEventOwner(Event $event): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return EventSubmission::where('event_id', $event->id)
            ->where('submitter_type', $user->getMorphClass())
            ->where('submitter_id', $user->id)
            ->exists();
    }

    /**
     * @return array{
     *   available: bool,
     *   reason: string|null,
     *   method: 'self_reported'|'registered_self_checkin',
     *   registration_id: string|null
     * }
     */
    protected function resolveCheckInState(User $user): array
    {
        return app(ResolveEventCheckInStateAction::class)->handle(
            $this->event->loadMissing('accessPolicy'),
            $user,
        );
    }
}
