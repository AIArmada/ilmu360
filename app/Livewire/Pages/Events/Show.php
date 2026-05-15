<?php

namespace App\Livewire\Pages\Events;

use App\Actions\Events\RecordEventCheckInAction;
use App\Actions\Events\ResolveEventCheckInStateAction;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventStructure;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use App\Enums\ScheduleState;
use App\Filament\Ahli\Resources\Events\EventResource as AhliEventResource;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\EventCheckin;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;
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

    public bool $isSaved = false;

    public bool $isGoing = false;

    public bool $isCheckedIn = false;

    public int $goingCount = 0;

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

        $event->load([
            'media',
            'organizer',
            'institution.media',
            'institution.address.state',
            'institution.address.city',
            'institution.address.district',
            'institution.address.subdistrict',
            'institution.contacts',
            'venue.media',
            'venue.address.state',
            'venue.address.city',
            'venue.address.district',
            'venue.address.subdistrict',
            'speakers.media',
            'keyPeople.speaker.media',
            'tags',
            'donationChannel.media',
            'settings',
            'series',
            'references.media',
            'languages',
            'latestPublishedChangeAnnouncement.replacementEvent.media',
            'latestPublishedChangeAnnouncement.replacementEvent.institution.media',
            'latestPublishedChangeAnnouncement.replacementEvent.speakers.media',
            'latestPublishedReplacementAnnouncement.replacementEvent.media',
            'latestPublishedReplacementAnnouncement.replacementEvent.institution.media',
            'latestPublishedReplacementAnnouncement.replacementEvent.speakers.media',
            'publishedChangeAnnouncements.replacementEvent',
            'childEvents.media',
            'childEvents.institution.media',
            'childEvents.institution.address.state',
            'childEvents.institution.address.district',
            'childEvents.venue.media',
            'childEvents.venue.address.state',
            'childEvents.venue.address.district',
        ]);

        $event->loadMorph('organizer', [
            Institution::class => ['media', 'contacts'],
            Speaker::class => ['media'],
            Venue::class => ['media'],
        ]);

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

    /**
     * @return Collection<int, Event>
     */
    #[Computed]
    public function publicChildEvents(): Collection
    {
        if (! $this->event->isParentProgram()) {
            return collect();
        }

        return $this->event->childEvents
            ->filter(fn (Event $childEvent): bool => $childEvent->is_active
                && in_array((string) $childEvent->status, Event::PUBLIC_STATUSES, true)
                && $childEvent->visibility === EventVisibility::Public)
            ->sortBy('starts_at')
            ->values();
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
        return $this->event->schedule_state === ScheduleState::Postponed;
    }

    #[Computed]
    public function eventActionsDisabled(): bool
    {
        return $this->isCancelledStatus($this->event) || $this->isPostponedWithoutConfirmedTime();
    }

    public function registrationMode(): RegistrationMode
    {
        $mode = $this->event->settings?->registration_mode;

        if ($mode instanceof RegistrationMode) {
            return $mode;
        }

        return RegistrationMode::Event;
    }

    /**
     * @return array{create_child_url: string, ahli_url: string}|null
     */
    #[Computed]
    public function parentProgramManagementLinks(): ?array
    {
        if (! $this->event->isParentProgram()) {
            return null;
        }

        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('update', $this->event)) {
            return null;
        }

        return [
            'create_child_url' => route('submit-event.create', ['parent' => $this->event]),
            'ahli_url' => AhliEventResource::getUrl('view', ['record' => $this->event], panel: 'ahli'),
        ];
    }

    /**
     * @return Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, EventKeyPerson>>
     */
    #[Computed]
    public function keyPeopleByRole(): Collection
    {
        return collect($this->event->keyPeople
            ->filter(fn (EventKeyPerson $keyPerson): bool => $keyPerson->role !== EventKeyPersonRole::Speaker && $keyPerson->is_public)
            ->groupBy(function (EventKeyPerson $keyPerson): string {
                $role = $keyPerson->role;

                return $role instanceof EventKeyPersonRole ? $role->value : (string) $role;
            })
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
        return $this->descriptionHtml() !== '' || $this->event->tags->isNotEmpty();
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
            $user->{$relation}()->detach($this->event->id);
            $this->event->decrement($countColumn);

            if ($countProperty) {
                $this->{$countProperty} = max(0, $this->{$countProperty} - 1);
            }

            $this->{$stateProperty} = false;
        } else {
            $user->{$relation}()->syncWithoutDetaching([$this->event->id]);
            $this->event->increment($countColumn);

            if ($countProperty) {
                $this->{$countProperty}++;
            }

            $this->{$stateProperty} = true;
            $this->recordEngagementOutcome($relation, $user);
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
        $this->goingCount = max(0, (int) ($this->event->going_count ?? 0));

        $user = auth()->user();

        if (! $user instanceof User) {
            $this->isSaved = false;
            $this->isGoing = false;
            $this->isCheckedIn = false;

            return;
        }

        $this->isSaved = $user->savedEvents()->where('event_id', $this->event->id)->exists();
        $this->isGoing = $user->goingEvents()->where('event_id', $this->event->id)->exists();
        $this->isCheckedIn = EventCheckin::query()
            ->where('event_id', $this->event->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @return array{url: string, thumb: string, alt: string}
     */
    protected function buildGalleryImagePayload(Media $media, string $fallbackAlt): array
    {
        $fullImageUrl = $media->getAvailableUrl(['preview', 'thumb']);
        $thumbnailUrl = $media->getAvailableUrl(['thumb']);

        return [
            'url' => $fullImageUrl !== '' ? $fullImageUrl : $media->getUrl(),
            'thumb' => $thumbnailUrl !== '' ? $thumbnailUrl : ($fullImageUrl !== '' ? $fullImageUrl : $media->getUrl()),
            'alt' => filled($media->name) ? (string) $media->name : $fallbackAlt,
        ];
    }

    public function render(): View
    {
        if ($this->event->isParentProgram()) {
            return view('livewire.pages.events.show-parent-program');
        }

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
        if (! $event->is_active || $event->visibility !== EventVisibility::Public || $event->eventStructure() === EventStructure::ParentProgram) {
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
     * Determine if the currently authenticated user is the owner of the event.
     * Checks both user_id (owner) and submitter_id (submitter).
     */
    protected function isEventOwner(Event $event): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($event->user_id === $user->id || $event->submitter_id === $user->id) {
            return true;
        }

        return EventSubmission::where('event_id', $event->id)
            ->where('submitted_by', $user->id)
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
            $this->event->loadMissing('settings'),
            $user,
        );
    }
}
