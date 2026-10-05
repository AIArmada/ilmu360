<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Models\Event;
use App\Models\Institution;
use App\Services\CalendarService;
use App\Services\PublicScheduleDiscoveryService;
use App\Support\Events\PublicSchedulePolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

#[Layout('layouts.app')]
#[Title('Maklumat Sesi')]
class Session extends Occurrence
{
    #[Locked]
    public EventSession $session;

    public function mount(Event $event, string $occurrenceSlug = '', string $sessionSlug = ''): void
    {
        parent::mount($event, $occurrenceSlug);

        $session = app(PublicScheduleDiscoveryService::class)->findSession($this->occurrence, $sessionSlug);

        if (! $session instanceof EventSession || ! PublicSchedulePolicy::isMeaningfulSession($session)) {
            abort(404);
        }

        OwnerContext::withOwner(null, function () use ($session): void {
            $session->loadMissing([
                'media',
                'locations' => $this->publicLocationScope($this->event),
                'locations.venueSpace',
                'locations.locationable' => static function (MorphTo $relation): void {
                    $relation->morphWith([
                        Institution::class => ['names', 'addresses.areaAssignments.area'],
                    ]);
                },
                'involvements' => $this->publicInvolvementScope(),
                'involvements.involveable' => $this->involveableEagerLoad(),
                'involvements.role',
                ...$this->commerceRelations(),
            ]);

            $this->occurrence->loadMissing($this->commerceRelations());

            // The selected session's own locations load after the first
            // hydration pass, so their venues need the same application-side
            // hydration as the rest of the schedule graph.
            app(PublicScheduleDiscoveryService::class)->hydrateOccurrencePageVenues($this->event);
        });

        $this->session = $session;
    }

    public function selectedSession(): ?EventSession
    {
        return $this->session ?? null;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function calendarLinks(): array
    {
        if ($this->eventActionsDisabled()
            || (string) $this->selectedOccurrence()?->status === 'cancelled'
            || (string) $this->selectedSession()?->status === 'cancelled') {
            return [];
        }

        $session = $this->selectedSession();

        if (! $session instanceof EventSession) {
            return parent::calendarLinks();
        }

        return app(CalendarService::class)->getAllCalendarLinksFor($this->event, $session, $this->selectedOccurrence());
    }

    /**
     * Session pages pay for the session leaf plus its parent date commerce;
     * sibling sessions never widen the budget.
     */
    protected function loadSelectedCommerce(): void
    {
        // Intentionally empty: commerce loads for the selected session and its
        // parent date in mount().
    }

    public function render(): View
    {
        return view('livewire.pages.events.session');
    }

    #[Computed]
    public function descriptionHtml(): string
    {
        $description = trim((string) ($this->selectedSession()->description ?? ''));

        return $description !== '' ? nl2br(e($description)) : parent::descriptionHtml();
    }

    /**
     * Own poster and gallery first, then the parent date and event visuals.
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

        $session = $this->selectedSession();

        if ($session instanceof EventSession) {
            $collect($session->getMedia('poster'));
            $collect($session->getMedia('gallery'));
        }

        $occurrence = $this->selectedOccurrence();

        if ($occurrence instanceof EventOccurrence) {
            $collect($occurrence->getMedia('poster'));
            $collect($occurrence->getMedia('gallery'));
        }

        $collect($this->event->getMedia('gallery'));

        return $images;
    }

    #[Computed]
    public function hasAboutContent(): bool
    {
        return filled($this->selectedSession()?->summary) || $this->descriptionHtml() !== '';
    }
}
