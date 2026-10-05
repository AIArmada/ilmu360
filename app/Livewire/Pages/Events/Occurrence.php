<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Persons\Enums\AssignmentStatus;
use App\Models\Event;
use App\Models\Person;
use App\Models\Reference;
use App\Services\CalendarService;
use App\Services\PublicScheduleDiscoveryService;
use App\Support\Events\PublicSchedulePolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

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
    }

    public function selectedOccurrence(): ?EventOccurrence
    {
        return $this->occurrence ?? null;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function calendarLinks(): array
    {
        if ($this->eventActionsDisabled()
            || (string) $this->selectedOccurrence()?->status === 'cancelled') {
            return [];
        }

        $occurrence = $this->selectedOccurrence();

        if (! $occurrence instanceof EventOccurrence) {
            return parent::calendarLinks();
        }

        return app(CalendarService::class)->getAllCalendarLinksFor($this->event, $occurrence);
    }

    public function render(): View
    {
        return view('livewire.pages.events.occurrence');
    }

    /**
     * Own poster and gallery first, then the shared parent gallery, so a
     * provided child visual is never hidden behind the parent-only set.
     *
     * @return array<int, array{url: string, thumb: string, alt: string}>
     */
    #[Computed]
    public function galleryImages(): array
    {
        $images = [];
        $imageCounter = 1;
        $seen = [];

        $collect = function (iterable $mediaItems) use (&$images, &$imageCounter, &$seen): void {
            foreach ($mediaItems as $media) {
                $key = (string) $media->getKey();

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $images[] = $this->buildGalleryImagePayload(
                    $media,
                    __('Photo :number', ['number' => $imageCounter++])
                );
            }
        };

        $occurrence = $this->selectedOccurrence();

        if ($occurrence instanceof EventOccurrence) {
            $collect($occurrence->getMedia('poster'));
            $collect($occurrence->getMedia('gallery'));
        }

        $collect($this->event->getMedia('gallery'));

        return $images;
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
            'venue.media',
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
            'occurrences.locations' => $locationScope,
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

    /**
     * Commerce and session detail for the selected occurrence, plus its own
     * people. Session pages override this to a no-op and load the selected
     * session leaf (and its parent date commerce) instead.
     */
    protected function loadSelectedCommerce(): void
    {
        $occurrence = $this->selectedOccurrence();

        if (! $occurrence instanceof EventOccurrence) {
            return;
        }

        OwnerContext::withOwner(null, function () use ($occurrence): void {
            $occurrence->loadMissing([
                'involvements' => $this->publicInvolvementScope(),
                'involvements.involveable' => $this->involveableEagerLoad(),
                'involvements.role',
                ...$this->sessionSubtreeRelations(),
                ...$this->commerceRelations(),
            ]);
        });
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function sessionSubtreeRelations(): array
    {
        return [
            'sessions.media',
            'sessions.locations' => $this->publicLocationScope($this->event),
            'sessions.locations.venueSpace',
            'sessions.involvements' => $this->publicInvolvementScope(),
            'sessions.involvements.involveable' => $this->involveableEagerLoad(),
        ];
    }

    protected function publicInvolvementScope(): \Closure
    {
        return static function (Relation $query): Relation {
            if ($query->getParent() instanceof EventOccurrence) {
                $query->whereNull('event_session_id');
            }

            return $query
                ->where('status', 'active')
                ->where('visibility', 'public');
        };
    }

    protected function involveableEagerLoad(): \Closure
    {
        return static function (MorphTo $relation): void {
            $relation->morphWith([
                Person::class => [
                    'titleAssignments' => fn (Relation $query) => $query
                        ->where('status', AssignmentStatus::Active)
                        ->with('title.category'),
                ],
            ]);
        };
    }

    /**
     * Owning-scope commerce for the selected leaf: tickets with seating and
     * stock, seat maps, links, materials, access policy and references.
     *
     * @return array<int|string, mixed>
     */
    protected function commerceRelations(): array
    {
        return [
            'ticketTypes' => $this->publicTicketScope(),
            'ticketTypes.seatingOptions',
            'ticketTypes.inventoryLevels',
            'seatMaps' => $this->publicSeatMapScope(),
            'seatMaps.sections',
            'links' => $this->publicLinkScope(),
            'materials' => $this->publicMaterialScope(),
            'accessPolicies' => static function (Relation $query): void {
                if ($query->getParent() instanceof EventOccurrence) {
                    $query->whereNull('event_session_id');
                }
            },
            'references' => function (Relation $query): void {
                if ($query->getParent() instanceof EventOccurrence) {
                    $query->whereNull('event_session_id');
                }

                $query->where('visibility', 'public')->orderBy('sort_order');
            },
            'references.referenceable' => function (MorphTo $relation): void {
                $relation->constrain([
                    Reference::class => fn (Builder $query) => Reference::applyPublicVisibility($query)
                        ->with(['authors', 'parentReference']),
                ]);
            },
        ];
    }
}
