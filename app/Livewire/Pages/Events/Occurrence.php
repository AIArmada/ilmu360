<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use App\Models\Event;
use App\Models\Reference;
use App\Services\PublicScheduleDiscoveryService;
use App\Services\ShareTrackingService;
use App\Support\Events\PublicSchedulePolicy;
use App\Support\Events\PublicScheduleSlug;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * A single date of a multi-date majlis.
 *
 * The event page renders this same view when the event has exactly one public
 * date, so everything shared with that path lives in Show. This subclass only
 * contributes the date selection, the date-scoped URL and the date-scoped
 * share tracking.
 */
#[Layout('layouts.app')]
#[Title('Maklumat Majlis')]
class Occurrence extends Show
{
    #[Locked]
    public EventOccurrence $occurrence;

    public function mount(Event $event, string $occurrenceSlug = ''): void
    {
        $discovery = app(PublicScheduleDiscoveryService::class);

        // Load the public schedule set first with counts attached, so the
        // slug resolver below reuses the loaded relation instead of issuing
        // its own exists query.
        $scheduleScope = $this->publicScheduleScope($event);
        $capacityScope = $this->capacityRegistrationScope();

        $event->load(['occurrences' => function (Relation $query) use ($scheduleScope, $capacityScope): void {
            $scheduleScope($query);
            $query
                ->withCount(['registrations' => $capacityScope])
                ->withSum(['registrations' => $capacityScope], 'total_participants')
                ->orderBy('starts_at')
                ->orderBy('created_at')
                ->orderBy('id');
        }]);

        $occurrence = $discovery->findOccurrence($event, $occurrenceSlug);

        if (! $occurrence instanceof EventOccurrence || ! PublicSchedulePolicy::isPublicOccurrence($occurrence)) {
            abort(404);
        }

        $this->authorizeEventView($event);

        OwnerContext::withOwner(null, function () use ($event, $discovery): void {
            // Event-level admission aggregates (mirrors Show::mount): without
            // them the shared ticket card reads missing counts as zero and
            // advertises places on a full event.
            $event->loadCount(['registrations' => $this->capacityRegistrationScope()]);
            $event->loadSum(['registrations' => $this->capacityRegistrationScope()], 'total_participants');
            $event->loadMissing($this->occurrenceBaseRelations($event));
            $discovery->hydrateOccurrencePageVenues($event);
        });

        /** @var EventOccurrence|null $loadedOccurrence */
        $loadedOccurrence = $event->occurrences->firstWhere('id', $occurrence->getKey());

        if (! $loadedOccurrence instanceof EventOccurrence) {
            abort(404);
        }

        $this->event = $event;
        $this->occurrence = $loadedOccurrence;
        $this->loadSelectedCommerce();

        // Show::mount() is not reached from here, so the going/saved/check-in
        // state has to be synced explicitly — otherwise the date page renders
        // "Akan Hadir" as off for a reader who already tapped it.
        $this->syncEngagementStates();
    }

    public function selectedOccurrence(): ?EventOccurrence
    {
        return $this->occurrence ?? null;
    }

    protected function occurrenceUrl(): string
    {
        return route('events.occurrence', [
            $this->event,
            PublicScheduleSlug::occurrence($this->occurrence),
        ]);
    }

    protected function engagementReturnUrl(): string
    {
        return $this->occurrenceUrl();
    }

    /**
     * Share tracking has to point at the date the reader is on; the inherited
     * implementation always builds the parent event URL.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function shareLinks(): array
    {
        $title = trim((string) ($this->occurrence->title ?: $this->event->title));

        return app(ShareTrackingService::class)->redirectLinks(
            $this->event->occurrences->count() === 1 ? route('events.show', $this->event) : $this->occurrenceUrl(),
            trim($title.' - '.config('app.name')),
            $title,
        );
    }

    /**
     * Enriched base graph for the schedule child pages: event identity and
     * shared public info (organizers, people, classification, audience,
     * languages, references, links, media, applicable event commerce), the
     * public-filtered location fallback chain and the bare schedule set. The
     * selected leaf loads its own commerce in loadSelectedCommerce() so
     * sibling dates never widen the query budget.
     *
     * @return array<int|string, mixed>
     */
    protected function occurrenceBaseRelations(Event $event): array
    {
        $scheduleScope = $this->publicScheduleScope($event);
        $locationScope = $this->publicLocationScope($event);
        $capacityScope = $this->capacityRegistrationScope();

        return [
            'media' => fn ($query) => $query
                ->whereIn('collection_name', ['cover', 'poster', 'gallery'])
                ->ordered(),
            'primaryOrganizerInvolvement.involveable',
            'institution.media',
            // Organisers are the fallback location, so their phone and e-mail
            // are the reader's only way to ask about a change.
            'institution.contactMethods',
            'venue.media',
            'venue.contactMethods',
            'persons' => function (Relation $query) use ($event): void {
                if (! $this->isEventOwner($event)) {
                    $query->where('event_involvements.visibility', 'public');
                }
            },
            'persons.media',
            'classifications.term',
            'audiences',
            'audienceProfiles',
            'keyPeople.person',
            'languages',
            'references' => function (Relation $query) use ($event): void {
                if (! $this->isEventOwner($event)) {
                    Reference::applyPublicVisibility($query->getQuery());
                    $query->where(config('events.database.tables.event_references', 'event_references').'.visibility', 'public');
                }

                $query->with(['media', 'authors']);
            },
            'links' => $this->publicLinkScope(),
            'materials' => $this->publicMaterialScope(),
            'ticketTypes' => $this->publicTicketScope(),
            'ticketTypes.seatingOptions',
            'ticketTypes.inventoryLevels',
            'seatMaps' => $this->publicSeatMapScope(),
            'seatMaps.sections',
            'accessPolicy',
            'primaryLocation' => $locationScope,
            'primaryLocation.venueSpace',
            'occurrences.media',
            'occurrences.locations' => function (Relation $query) use ($locationScope): void {
                $locationScope($query);
                $query->whereNull('event_session_id');
            },
            'occurrences.locations.venueSpace',
            'occurrences.sessions' => function (Relation $query) use ($scheduleScope, $capacityScope): void {
                $scheduleScope($query);
                $query
                    ->withCount(['registrations' => $capacityScope])
                    ->withSum(['registrations' => $capacityScope], 'total_participants')
                    ->orderBy('sort_order')
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
        ];
    }
}
