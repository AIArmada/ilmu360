<?php

namespace App\Livewire\Pages\Search;

use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Services\EventSearchService;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Search Majlis, Speakers, References & Institutions')]
class Index extends Component
{
    #[Url]
    public ?string $search = null;

    #[Url]
    public ?string $lat = null;

    #[Url]
    public ?string $lng = null;

    #[Url]
    public int $radius_km = 15;

    public function clearSearch(): void
    {
        $this->search = null;
    }

    public function clearLocation(): void
    {
        $this->lat = null;
        $this->lng = null;
        $this->radius_km = 15;
    }

    #[Computed]
    public function hasSearchContext(): bool
    {
        return $this->normalizedSearch() !== null || $this->locationIsActive();
    }

    #[Computed]
    public function hasTypedSearch(): bool
    {
        return $this->normalizedSearch() !== null;
    }

    /**
     * @return array{items: Collection<int, Event>, total: int}
     */
    #[Computed]
    public function eventResults(): array
    {
        if (! $this->hasSearchContext()) {
            return [
                'items' => collect(),
                'total' => 0,
            ];
        }

        $search = $this->normalizedSearch();
        $searchService = app(EventSearchService::class);

        $paginator = $this->locationIsActive()
            ? $searchService->searchNearbyWithQuery(
                query: $search,
                lat: $this->normalizedCoordinate($this->lat) ?? 0.0,
                lng: $this->normalizedCoordinate($this->lng) ?? 0.0,
                radiusKm: $this->normalizedRadius(),
                perPage: 6,
            )
            : $searchService->search(
                query: $search,
                perPage: 6,
                sort: $search !== null ? 'relevance' : 'time',
            );

        /** @var Collection<int, Event> $items */
        $items = collect($paginator->items())->values();

        return [
            'items' => $items,
            'total' => $paginator->total(),
        ];
    }

    /**
     * @return array{items: Collection<int, Person>, total: int}
     */
    #[Computed]
    public function personResults(): array
    {
        $search = $this->normalizedSearch();

        if ($search === null) {
            return [
                'items' => collect(),
                'total' => 0,
            ];
        }

        $query = $this->personSearchQuery($search);
        $total = (clone $query)->count();

        /** @var Collection<int, Person> $items */
        $items = $query
            ->orderBy('name')
            ->limit(4)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @return array{items: Collection<int, Institution>, total: int}
     */
    #[Computed]
    public function institutionResults(): array
    {
        $search = $this->normalizedSearch();

        if ($search === null) {
            return [
                'items' => collect(),
                'total' => 0,
            ];
        }

        $query = $this->institutionSearchQuery($search);
        $total = (clone $query)->count();

        /** @var Collection<int, Institution> $items */
        $items = $query
            ->orderBy('name')
            ->limit(4)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @return array{items: Collection<int, Reference>, total: int}
     */
    #[Computed]
    public function referenceResults(): array
    {
        $search = $this->normalizedSearch();

        if ($search === null) {
            return [
                'items' => collect(),
                'total' => 0,
            ];
        }

        $query = $this->referenceSearchQuery($search);
        $total = (clone $query)->count();

        /** @var Collection<int, Reference> $items */
        $items = $query
            ->orderBy('title')
            ->limit(4)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    public function render(): View
    {
        return view('livewire.pages.search.index');
    }

    /**
     * @return Builder<Person>
     */
    private function personSearchQuery(string $search): Builder
    {
        return app(PersonSearchService::class)->applyIndexedSearch(
            Person::query()
                ->active()
                ->where('status', 'verified')
                ->withCount(['events' => function (Builder $query): void {
                    $query
                        ->whereNotNull('events.published_at')
                        ->whereIn('events.status', Event::PUBLIC_STATUSES)
                        ->where('events.visibility', EventVisibility::Public)
                        ->whereHas('occurrences')
                        ->where('events.starts_at', '>=', now());
                }])
                ->with('media'),
            $search,
        );
    }

    /**
     * @return Builder<Institution>
     */
    private function institutionSearchQuery(string $search): Builder
    {
        return app(InstitutionSearchService::class)->applySearch(
            Institution::query()
                ->select('institutions.*')
                ->active()
                ->where('status', 'verified')
                ->selectSub($this->institutionPublicEventCountSubquery(), 'events_count')
                ->with(['addresses', 'media']),
            $search,
        );
    }

    /**
     * @return Builder<Event>
     */
    private function institutionPublicEventCountSubquery(): Builder
    {
        return Event::query()
            ->selectRaw('count(*)')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public)
            ->whereHas('occurrences')
            ->where('events.starts_at', '>=', now());
    }

    /**
     * @return Builder<Reference>
     */
    private function referenceSearchQuery(string $search): Builder
    {
        return app(ReferenceSearchService::class)->applySearch(
            Reference::query()
                ->active()
                ->where('status', 'verified')
                ->root()
                ->withCount(['events' => function (Builder $query): void {
                    $query
                        ->whereNotNull('events.published_at')
                        ->whereIn('events.status', Event::PUBLIC_STATUSES)
                        ->where('events.visibility', EventVisibility::Public)
                        ->whereHas('occurrences')
                        ->where('events.starts_at', '>=', now());
                }])
                ->with('media'),
            $search,
        );
    }

    private function normalizedSearch(): ?string
    {
        if (! is_string($this->search)) {
            return null;
        }

        $normalizedSearch = trim($this->search);

        return $normalizedSearch === '' ? null : $normalizedSearch;
    }

    private function locationIsActive(): bool
    {
        return $this->normalizedCoordinate($this->lat) !== null
            && $this->normalizedCoordinate($this->lng) !== null;
    }

    private function normalizedCoordinate(?string $value): ?float
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function normalizedRadius(): int
    {
        return max(1, min($this->radius_km, 100));
    }
}
