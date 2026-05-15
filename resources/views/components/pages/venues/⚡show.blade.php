<?php

use App\Models\Event;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public Venue $venue;

    public int $upcomingPerPage = 8;

    public int $pastPerPage = 8;

    public function mount(Venue $venue): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        if (! $venue->is_active) {
            abort(404);
        }

        if ($venue->status !== 'verified' && ! $canBypassVisibility) {
            abort(404);
        }

        $this->venue = $venue->load([
            'media',
            'address.state',
            'address.city',
            'address.district',
            'address.subdistrict',
            'address.country',
            'contacts',
            'socialMedia',
        ]);
    }

    public function loadMoreUpcoming(): void
    {
        $this->upcomingPerPage += 8;
    }

    public function loadMorePast(): void
    {
        $this->pastPerPage += 8;
    }

    /**
     * @return Collection<int, Event>
     */
    public function getUpcomingEventsProperty(): Collection
    {
        return $this->venue->events()
            ->active()
            ->where('starts_at', '>=', now())
            ->with([
                'institution.media',
                'institution.address.state',
                'institution.address.district',
                'institution.address.subdistrict',
                'speakers.media',
                'keyPeople.speaker.media',
                'media',
            ])
            ->orderBy('starts_at')
            ->take($this->upcomingPerPage)
            ->get();
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->venue->events()
            ->active()
            ->where('starts_at', '>=', now())
            ->count();
    }

    /**
     * @return Collection<int, Event>
     */
    public function getPastEventsProperty(): Collection
    {
        return $this->venue->events()
            ->active()
            ->where('starts_at', '<', now())
            ->with([
                'institution.media',
                'institution.address.state',
                'institution.address.district',
                'institution.address.subdistrict',
                'speakers.media',
                'keyPeople.speaker.media',
                'media',
            ])
            ->orderByDesc('starts_at')
            ->take($this->pastPerPage)
            ->get();
    }

    public function getPastTotalProperty(): int
    {
        return $this->venue->events()
            ->active()
            ->where('starts_at', '<', now())
            ->count();
    }

    public function rendering($view): void
    {
        $view->title($this->venue->name.' - '.config('app.name'));
    }
};

?>

@section('title', $this->venue->name . ' - ' . config('app.name'))
@section('meta_description', Str::limit(trim(strip_tags((string) $this->venue->description)) ?: __('Lihat profil lokasi, alamat, dan majlis yang diadakan di :name.', ['name' => $this->venue->name]), 160))
@section('meta_robots', ($this->venue->is_active && $this->venue->status === 'verified') ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('venues.show', $this->venue))
@section('og_image', $this->venue->getFirstMediaUrl('cover', 'banner') ?: asset('images/placeholders/venue.png'))
@section('og_image_alt', __('Lokasi :name', ['name' => $this->venue->name]))

@php
    $venue = $this->venue;
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;
    $coverUrl = $venue->getFirstMediaUrl('cover', 'banner') ?: asset('images/placeholders/venue.png');
    $thumbUrl = $venue->getFirstMediaUrl('cover', 'thumb') ?: asset('images/placeholders/venue.png');
    $address = $venue->addressModel;
    $addressParts = array_values(array_filter([
        $address?->line1,
        $address?->line2,
        $address?->postcode,
        $address?->subdistrict?->name,
        $address?->district?->name,
        $address?->state?->name,
        $address?->country?->name,
    ], fn (mixed $value): bool => filled($value)));
    $addressText = $addressParts !== [] ? implode(', ', $addressParts) : __('Alamat akan dikemas kini kemudian.');
    $contactCards = $venue->contacts->where('is_public', true)->values();
    $socialLinks = $venue->socialMedia
        ->filter(fn ($social) => filled($social->resolved_url) && filled($social->platform))
        ->values();
    $facilityLabels = collect((array) $venue->facilities)
        ->filter(fn (mixed $enabled): bool => (bool) $enabled)
        ->keys()
        ->map(fn (string $key): string => Str::headline(str_replace('_', ' ', $key)))
        ->values();
@endphp

<div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.12),_transparent_35%),linear-gradient(180deg,#f8fafc_0%,#eefbf5_45%,#f8fafc_100%)] pb-24">
    <section class="relative overflow-hidden border-b border-emerald-100/70 bg-white/80 backdrop-blur">
        <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row lg:items-end lg:px-8 lg:py-14">
            <div class="w-full lg:max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-[0.32em] text-emerald-600">{{ __('Lokasi') }}</p>
                <h1 class="mt-4 text-4xl font-black tracking-tight text-slate-900 sm:text-5xl">{{ $venue->name }}</h1>
                <div class="mt-4 flex flex-wrap gap-3 text-sm text-slate-600">
                    <span class="rounded-full bg-emerald-100 px-3 py-1 font-medium text-emerald-800">{{ $venue->type?->getLabel() ?? Str::headline((string) $venue->type) }}</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1">{{ __(':count majlis', ['count' => $venue->events()->count()]) }}</span>
                </div>
                <p class="mt-6 max-w-3xl text-base leading-8 text-slate-700">{{ trim(strip_tags((string) $venue->description)) ?: __('Ruang ini digunakan untuk pelbagai majlis ilmu dan program komuniti. Semak alamat, kemudahan, dan senarai majlis yang pernah atau akan berlangsung di sini.') }}</p>
                <div class="mt-6 rounded-3xl border border-slate-200 bg-slate-50/80 p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Alamat') }}</p>
                    <p class="mt-3 text-sm leading-7 text-slate-700">{{ $addressText }}</p>
                </div>
            </div>

            <div class="w-full lg:ml-auto lg:max-w-md">
                <div class="overflow-hidden rounded-[2rem] border border-white/70 bg-white shadow-[0_24px_80px_-40px_rgba(15,23,42,0.45)]">
                    <img src="{{ $coverUrl }}" alt="{{ $venue->name }}" class="h-64 w-full object-cover sm:h-72">
                </div>
            </div>
        </div>
    </section>

    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.9fr)] lg:px-8">
        <div class="space-y-8">
            <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Majlis Akan Datang') }}</p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ __('Ruang yang bakal digunakan') }}</h2>
                    </div>
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-800">{{ $upcomingTotal }}</span>
                </div>

                <div class="mt-6 space-y-4">
                    @forelse ($upcomingEvents as $event)
                        <a href="{{ route('events.show', $event) }}" wire:navigate class="group flex flex-col gap-4 rounded-3xl border border-slate-200 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:border-emerald-200 hover:bg-white hover:shadow-lg hover:shadow-emerald-950/5 sm:flex-row sm:items-center">
                            <img src="{{ $event->getFirstMediaUrl('poster', 'thumb') ?: ($event->institution?->getFirstMediaUrl('logo', 'thumb') ?: $thumbUrl) }}" alt="{{ $event->title }}" class="h-24 w-full rounded-2xl object-cover sm:h-20 sm:w-28">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-600">{{ optional($event->starts_at)?->timezone($event->timezone ?? config('app.timezone'))->translatedFormat('D, j M Y g:i A') }}</p>
                                <h3 class="mt-2 text-lg font-semibold text-slate-900 transition group-hover:text-emerald-700">{{ $event->title }}</h3>
                                @if ($event->institution)
                                    <p class="mt-2 text-sm text-slate-600">{{ $event->institution->name }}</p>
                                @endif
                            </div>
                        </a>
                    @empty
                        <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-sm leading-7 text-slate-600">{{ __('Belum ada majlis akan datang untuk lokasi ini.') }}</div>
                    @endforelse
                </div>

                @if ($upcomingTotal > $this->upcomingPerPage)
                    <button type="button" wire:click="loadMoreUpcoming" class="mt-6 inline-flex items-center rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">{{ __('Lihat Lagi') }}</button>
                @endif
            </section>

            <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Arkib Majlis') }}</p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ __('Majlis yang pernah diadakan') }}</h2>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700">{{ $pastTotal }}</span>
                </div>

                <div class="mt-6 space-y-4">
                    @forelse ($pastEvents as $event)
                        <a href="{{ route('events.show', $event) }}" wire:navigate class="group flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white p-5 transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg hover:shadow-slate-950/5 sm:flex-row sm:items-center">
                            <img src="{{ $event->getFirstMediaUrl('poster', 'thumb') ?: ($event->institution?->getFirstMediaUrl('logo', 'thumb') ?: $thumbUrl) }}" alt="{{ $event->title }}" class="h-24 w-full rounded-2xl object-cover sm:h-20 sm:w-28">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">{{ optional($event->starts_at)?->timezone($event->timezone ?? config('app.timezone'))->translatedFormat('D, j M Y g:i A') }}</p>
                                <h3 class="mt-2 text-lg font-semibold text-slate-900 transition group-hover:text-emerald-700">{{ $event->title }}</h3>
                                @if ($event->institution)
                                    <p class="mt-2 text-sm text-slate-600">{{ $event->institution->name }}</p>
                                @endif
                            </div>
                        </a>
                    @empty
                        <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-sm leading-7 text-slate-600">{{ __('Belum ada rekod majlis lepas untuk lokasi ini.') }}</div>
                    @endforelse
                </div>

                @if ($pastTotal > $this->pastPerPage)
                    <button type="button" wire:click="loadMorePast" class="mt-6 inline-flex items-center rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-50">{{ __('Lihat Lagi') }}</button>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Butiran Lokasi') }}</p>
                <div class="mt-5 space-y-4 text-sm text-slate-700">
                    <div>
                        <p class="font-medium text-slate-900">{{ __('Nama Lokasi') }}</p>
                        <p class="mt-1">{{ $venue->name }}</p>
                    </div>
                    <div>
                        <p class="font-medium text-slate-900">{{ __('Jenis') }}</p>
                        <p class="mt-1">{{ $venue->type?->getLabel() ?? Str::headline((string) $venue->type) }}</p>
                    </div>
                    <div>
                        <p class="font-medium text-slate-900">{{ __('Alamat Penuh') }}</p>
                        <p class="mt-1 leading-7">{{ $addressText }}</p>
                    </div>
                </div>
            </section>

            @if ($facilityLabels->isNotEmpty())
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Kemudahan') }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($facilityLabels as $label)
                            <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-800">{{ $label }}</span>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($contactCards->isNotEmpty())
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Hubungi') }}</p>
                    <div class="mt-4 space-y-3">
                        @foreach ($contactCards as $contact)
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $contact->category_label }}</p>
                                <p class="mt-2 break-all text-sm font-medium text-slate-900">{{ $contact->value }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($socialLinks->isNotEmpty())
                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Media Sosial') }}</p>
                    <div class="mt-4 space-y-3">
                        @foreach ($socialLinks as $social)
                            <a href="{{ $social->resolved_url }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-emerald-200 hover:bg-white hover:text-emerald-700">
                                <span>{{ Str::headline((string) $social->platform) }}</span>
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </aside>
    </div>
</div>