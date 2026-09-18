<?php

use App\Models\Venue;
use App\Support\Location\LocationSlugResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new
    class extends Component
    {
        use WithPagination;

        /**
         * Mirror of the parent filter state. Plain props on purpose: the parent
         * owns the URL, this component only consumes filter snapshots via mount
         * (first paint) and syncFilters (later updates, each its own request so
         * the filter dropdowns never wait for this list query).
         */
        public ?string $search = null;

        public ?string $country_id = null;

        public ?string $state_id = null;

        public ?string $district_id = null;

        public ?string $subdivision_id = null;

        /**
         * @param  array{search?: ?string, country_id?: ?string, state_id?: ?string, district_id?: ?string, subdivision_id?: ?string}  $filters
         */
        public function mount(array $filters = []): void
        {
            $this->applyFilters($filters);

            // Preserve ?page= deep links: page state lives here, not in the URL.
            $page = (int) request('page', 1);

            if ($page > 1) {
                $this->setPage($page);
            }
        }

        /**
         * @param  array{search?: ?string, country_id?: ?string, state_id?: ?string, district_id?: ?string, subdivision_id?: ?string}  $filters
         */
        #[On('venue-filters-updated')]
        public function syncFilters(array $filters): void
        {
            $this->applyFilters($filters);
            $this->resetPage();
        }

        /**
         * @param  array{search?: ?string, country_id?: ?string, state_id?: ?string, district_id?: ?string, subdivision_id?: ?string}  $filters
         */
        private function applyFilters(array $filters): void
        {
            // syncFilters is client-reachable: only strings survive, anything
            // else degrades to null (filter ignored) instead of throwing.
            $this->search = $this->filterString($filters, 'search');
            $this->country_id = $this->filterString($filters, 'country_id');
            $this->state_id = $this->filterString($filters, 'state_id');
            $this->district_id = $this->filterString($filters, 'district_id');
            $this->subdivision_id = $this->filterString($filters, 'subdivision_id');
        }

        private function filterString(array $filters, string $key): ?string
        {
            $value = $filters[$key] ?? null;

            return is_string($value) ? $value : null;
        }

        #[Computed]
        public function venues(): LengthAwarePaginatorContract
        {
            $query = $this->baseVenuesQuery();
            $search = $this->normalizedSearch();

            if ($search !== null) {
                $this->applySearchScope($query, $search);
            }

            return $query
                ->orderBy('venues.name')
                ->paginate(12)
                ->withQueryString();
        }

        private function baseVenuesQuery(): Builder
        {
            $query = Venue::query()
                ->active()
                ->where('status', 'verified')
                ->withCount(['events' => function (Builder $query): void {
                    $query->active();
                }])
                ->with([
                    'addresses',
                    'media',
                ]);

            return $this->applyLocationScope($query);
        }

        private function applySearchScope(Builder $query, string $search): void
        {
            $operator = DB::connection($query->getModel()->getConnectionName())->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $collapsedWildcardSearch = '%'.str_replace(' ', '%', $search).'%';

            $query->where(function (Builder $innerQuery) use ($operator, $search, $collapsedWildcardSearch): void {
                $innerQuery
                    ->where('venues.name', $operator, "%{$search}%")
                    ->orWhere('venues.name', $operator, $collapsedWildcardSearch)
                    ->orWhere('venues.slug', $operator, "%{$search}%")
                    ->orWhere('venues.description', $operator, "%{$search}%")
                    ->orWhere('venues.description', $operator, $collapsedWildcardSearch)
                    ->orWhereHas('addresses', function (Builder $addressQuery) use ($operator, $search, $collapsedWildcardSearch): void {
                        $addressQuery
                            ->where('line1', $operator, "%{$search}%")
                            ->orWhere('line1', $operator, $collapsedWildcardSearch)
                            ->orWhere('line2', $operator, "%{$search}%")
                            ->orWhere('postcode', $operator, "%{$search}%")
                            ->orWhere('city', $operator, "%{$search}%")
                            ->orWhere('state', $operator, "%{$search}%")
                            ->orWhere('country', $operator, "%{$search}%");
                    });
            });
        }

        private function applyLocationScope(Builder $query): Builder
        {
            $countryId = $this->normalizedLocationId($this->country_id);
            $stateId = $this->normalizedLocationId($this->state_id);
            $adminArea1Id = $this->normalizedLocationId($this->district_id);
            $adminArea2Id = $this->normalizedLocationId($this->subdivision_id);

            if ($countryId === null && $stateId === null && $adminArea1Id === null && $adminArea2Id === null) {
                return $query;
            }

            $districtRole = $this->locationSlugResolver()->districtRoleForCountry($countryId);
            $subdivisionRole = $this->locationSlugResolver()->subdivisionRoleForCountry($countryId);

            return $query->whereHas('addresses', function (Builder $addressQuery) use ($countryId, $stateId, $adminArea1Id, $adminArea2Id, $districtRole, $subdivisionRole): void {
                if ($countryId !== null) {
                    $addressQuery->where('country_id', $countryId);
                }

                if ($stateId !== null) {
                    $addressQuery->where('state_id', $stateId);
                }

                if ($adminArea1Id !== null && $districtRole !== null) {
                    $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                        ->where('role', $districtRole)->where('address_area_id', $adminArea1Id));
                }

                if ($adminArea2Id !== null && $subdivisionRole !== null) {
                    $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                        ->where('role', $subdivisionRole)->where('address_area_id', $adminArea2Id));
                }
            });
        }

        private function locationSlugResolver(): LocationSlugResolver
        {
            return app(LocationSlugResolver::class);
        }

        private function normalizedSearch(): ?string
        {
            if (! is_string($this->search)) {
                return null;
            }

            $search = trim($this->search);

            return $search === '' ? null : $search;
        }

        private function normalizedLocationId(?string $value): ?string
        {
            if (! is_string($value)) {
                return null;
            }

            $normalized = trim($value);

            if ($normalized === '' || ! Str::isUuid($normalized)) {
                return null;
            }

            return $normalized;
        }

    };
?>

@php
    $venues = $this->venues;
    $venueTotal = $venues->total();
    // Event listeners travel as __dispatch calls; wire:target matches the call method, not syncFilters.
    $venueLoadingTarget = 'syncFilters,__dispatch,gotoPage,nextPage,previousPage';
    $formatVenueLocation = static function ($addressModel): string {
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($addressModel);

        return $parts === [] ? '-' : implode(', ', $parts);
    };
@endphp

<div class="container mx-auto mt-12 px-6 lg:px-12">
    <div wire:loading.delay.short wire:target="{{ $venueLoadingTarget }}">
        <x-ui.skeleton.institution-card-grid />
    </div>

    <div wire:loading.remove wire:target="{{ $venueLoadingTarget }}">
        @if($venues->isEmpty())
            <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50/50 py-24 text-center">
                <div class="mb-6 inline-flex h-20 w-20 items-center justify-center rounded-full bg-white text-slate-300 shadow-sm">
                    <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-slate-900">{{ __('No venues found') }}</h3>
                <p class="mx-auto mt-2 max-w-md text-slate-500">
                    {{ __('We couldn\'t find any venues matching your search or location filters.') }}
                </p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <button type="button" wire:click="$parent.clearFilters()" class="font-semibold text-emerald-600 hover:text-emerald-700">
                        {{ __('Clear Filters') }} &rarr;
                    </button>
                </div>
            </div>
        @else
            <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                @foreach($venues as $venue)
                    @php
                        $coverUrl = $venue->getFirstMediaUrl('cover', 'banner') ?: asset('images/placeholders/venue.png');
                        $address = $venue->primaryAddress();
                        $locationDisplay = $formatVenueLocation($address);
                        $venueType = $venue->venue_type;
                        $typeLabel = $venueType instanceof \App\Enums\VenueType
                            ? $venueType->getLabel()
                            : (filled($venueType) ? \Illuminate\Support\Str::headline((string) $venueType) : __('Venue'));
                    @endphp

                    <a
                        href="{{ route('venues.show', $venue) }}"
                        wire:navigate
                        wire:key="venue-{{ $venue->id }}"
                        class="group relative flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-900/8"
                    >
                        <div class="relative aspect-video overflow-hidden bg-slate-50">
                            <img src="{{ $coverUrl }}" alt="{{ $venue->name }}" class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" loading="lazy">
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/45 via-slate-900/10 to-transparent"></div>
                            <span class="absolute left-4 top-4 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-emerald-700 shadow-sm ring-1 ring-emerald-100">
                                {{ $typeLabel }}
                            </span>
                        </div>

                        <div class="flex flex-1 flex-col p-6">
                            <h3 class="mb-2 font-heading text-lg font-bold leading-tight text-slate-900 transition-colors group-hover:text-emerald-700">
                                {{ $venue->name }}
                            </h3>

                            <p class="mb-4 flex items-start gap-1.5 text-sm font-medium text-slate-600">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span class="line-clamp-2">{{ $locationDisplay }}</span>
                            </p>

                            <div class="mt-auto flex items-center justify-center border-t border-slate-100 pt-5">
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                    <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    {{ $venue->events_count }} {{ __('Events') }}
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-16">
                {{ $venues->links() }}
            </div>

            <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-4 text-center shadow-sm">
                <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __('Direktori Tempat') }}</p>
                <p class="mt-2 text-sm font-semibold text-slate-600">
                    {{ __('Jumlah tempat: :count', ['count' => number_format($venueTotal)]) }}
                </p>
            </div>
        @endif
    </div>
</div>

