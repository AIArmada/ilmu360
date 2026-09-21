<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function events(): int
    {
        return Cache::remember('home.stats.events.upcoming', 300, function (): int {
            return Event::active()
                ->where('starts_at', '>=', now())
                ->count('id');
        });
    }

    #[Computed]
    public function persons(): int
    {
        return Cache::remember('home.stats.persons.upcoming', 300, function (): int {
            return Person::active()
                ->whereHas('events', function ($query): void {
                    $query->active()
                        ->where('starts_at', '>=', now());
                })
                ->count('id');
        });
    }

    #[Computed]
    public function institutions(): int
    {
        return Cache::remember('home.stats.institutions.upcoming', 300, function (): int {
            return Event::active()
                ->where('starts_at', '>=', now())
                ->whereNotNull('institution_id')
                ->distinct()
                ->count('institution_id');
        });
    }
};
?>

<div class="mx-auto grid max-w-6xl grid-cols-2 overflow-hidden rounded-[1.75rem] border border-[#e4e9e2] bg-white shadow-[0_16px_40px_-28px_rgba(23,61,47,0.45)] sm:grid-cols-3 lg:grid-cols-5">
    <div class="flex items-center gap-3 border-b border-[#e7ece6] px-4 py-5 sm:px-5 lg:border-b-0 lg:border-r">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linejoin="round" stroke-width="1.7" d="M4 21V5l8-3 8 3v16M8 21v-6h8v6M8 7h.01M12 7h.01M16 7h.01"/></svg></span>
        <span><strong class="block font-heading text-xl font-bold leading-none text-[#142e27]">{{ number_format($this->events) }}</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Majlis Direkod') }}</small></span>
    </div>
    <div class="flex items-center gap-3 border-b border-[#e7ece6] px-4 py-5 sm:px-5 lg:border-b-0 lg:border-r">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><circle cx="9" cy="8" r="3" stroke-width="1.7"/><circle cx="17" cy="9" r="2.3" stroke-width="1.7"/><path stroke-linecap="round" stroke-width="1.7" d="M3.5 19a5.5 5.5 0 0 1 11 0M15 15.5a4.5 4.5 0 0 1 5.5 3.5"/></svg></span>
        <span><strong class="block font-heading text-xl font-bold leading-none text-[#142e27]">{{ number_format($this->persons) }}</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Penceramah') }}</small></span>
    </div>
    <div class="flex items-center gap-3 border-b border-[#e7ece6] px-4 py-5 sm:px-5 lg:border-b-0 lg:border-r">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linejoin="round" stroke-width="1.7" d="M5 21V5h14v16M3 21h18M8 9h2m4 0h2m-8 4h2m4 0h2m-8 4h2m4 0h2"/></svg></span>
        <span><strong class="block font-heading text-xl font-bold leading-none text-[#142e27]">{{ number_format($this->institutions) }}</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Institusi') }}</small></span>
    </div>
    <div class="flex items-center gap-3 border-b border-[#e7ece6] px-4 py-5 sm:px-5 sm:border-b-0 lg:border-r">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.2" stroke-width="1.7"/></svg></span>
        <span><strong class="block font-heading text-sm font-bold leading-tight text-[#142e27]">{{ __('Seluruh Malaysia') }}</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Cakupan Lokasi') }}</small></span>
    </div>
    <div class="flex items-center gap-3 px-4 py-5 sm:px-5">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><circle cx="8" cy="8" r="2.5" stroke-width="1.7"/><circle cx="16" cy="8" r="2.5" stroke-width="1.7"/><path stroke-linecap="round" stroke-width="1.7" d="M3.5 19a4.5 4.5 0 0 1 9 0M11.5 19a4.5 4.5 0 0 1 9 0"/></svg></span>
        <span><strong class="block font-heading text-xl font-bold leading-none text-[#142e27]">320,000+</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Ahli Komuniti') }}</small></span>
    </div>
</div>
