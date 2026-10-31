<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function events(): int
    {
        return Cache::remember('home.stats.events.active', 3600, function (): int {
            return Event::active()->count('id');
        });
    }

    #[Computed]
    public function persons(): int
    {
        return Cache::remember('home.stats.persons.active', 3600, function (): int {
            return Person::active()->count('id');
        });
    }

    #[Computed]
    public function institutions(): int
    {
        return Cache::remember('home.stats.institutions.active', 3600, function (): int {
            return Institution::active()->count('id');
        });
    }

    #[Computed]
    public function references(): int
    {
        return Cache::remember('home.stats.references.active', 3600, function (): int {
            return Reference::active()
                ->root()
                ->count('id');
        });
    }
};
?>

<div class="mx-auto grid max-w-6xl grid-cols-2 overflow-hidden rounded-[1.75rem] border border-[#e4e9e2] bg-white shadow-[0_16px_40px_-28px_rgba(23,61,47,0.45)] lg:grid-cols-4">
    <div class="flex items-center justify-center gap-3 border-b border-[#e7ece6] px-4 py-5 sm:px-5 lg:border-b-0 lg:border-r">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3.2V1.9" />
                <path d="M12 10.1c-1.7 0-3-1.3-3-3 0-1.5 1.2-2.9 3-4.6 1.8 1.7 3 3.1 3 4.6 0 1.7-1.3 3-3 3Z" fill="currentColor" fill-opacity=".12" />
                <path d="M6.4 20.4v-6.1c0-1.2 1-2.2 2.2-2.2h6.8c1.2 0 2.2 1 2.2 2.2v6.1" />
                <path d="M3.8 20.4h16.4" />
                <path d="M9.8 20.4v-2.9a2.2 2.2 0 0 1 4.4 0v2.9" fill="currentColor" fill-opacity=".08" />
            </svg>
        </span>
        <span><strong class="block font-heading text-xl font-bold leading-none text-[#142e27]">{{ number_format($this->events) }}</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Majlis') }}</small></span>
    </div>
    <div class="flex items-center justify-center gap-3 border-b border-[#e7ece6] px-4 py-5 sm:px-5 lg:border-b-0 lg:border-r">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="9.2" cy="7.6" r="3" fill="currentColor" fill-opacity=".12" />
                <path d="M3.6 20.2v-1.1c0-3.1 2.5-5.6 5.6-5.6s5.6 2.5 5.6 5.6v1.1" />
                <path d="M4.6 20.2h9.2" />
                <path d="M17.3 6.1a4.3 4.3 0 0 1 0 7M19.7 4.3a7.2 7.2 0 0 1 0 10.6" />
            </svg>
        </span>
        <span><strong class="block font-heading text-xl font-bold leading-none text-[#142e27]">{{ number_format($this->persons) }}</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Penceramah') }}</small></span>
    </div>
    <div class="flex items-center justify-center gap-3 border-[#e7ece6] px-4 py-5 sm:px-5 lg:border-r">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m12 3.1 7.4 4.2H4.6L12 3.1Z" fill="currentColor" fill-opacity=".14" />
                <path d="M5.8 20.3V7.3h12.4v13" />
                <path d="M4 20.3h16" />
                <path d="M9 20.3v-4.1a3 3 0 0 1 6 0v4.1" fill="currentColor" fill-opacity=".08" />
                <path d="M8.4 9.8h1.5M14.1 9.8h1.5M8.4 12.8h1.5M14.1 12.8h1.5" />
            </svg>
        </span>
        <span><strong class="block font-heading text-xl font-bold leading-none text-[#142e27]">{{ number_format($this->institutions) }}</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Institusi') }}</small></span>
    </div>
    <div class="flex items-center justify-center gap-3 px-4 py-5 sm:px-5">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff8f1] text-[#0a654a]">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 6.6C10.4 5.2 8.4 4.4 6.3 4.4c-.8 0-1.6.1-2.3.4v13.7c.7-.3 1.5-.4 2.3-.4 2.1 0 4.1.8 5.7 2.2 1.6-1.4 3.6-2.2 5.7-2.2.8 0 1.6.1 2.3.4V4.8c-.7-.3-1.5-.4-2.3-.4-2.1 0-4.1.8-5.7 2.2Z" fill="currentColor" fill-opacity=".08" />
                <path d="M12 6.6v13.7" />
                <path d="M8.3 7.7c1.3.1 2.5.6 3.7 1.4M15.7 7.7c-1.3.1-2.5.6-3.7 1.4" stroke-opacity=".55" />
            </svg>
        </span>
        <span><strong class="block font-heading text-xl font-bold leading-none text-[#142e27]">{{ number_format($this->references) }}</strong><small class="mt-1 block text-[0.68rem] leading-4 text-[#687b75]">{{ __('Rujukan') }}</small></span>
    </div>
</div>
