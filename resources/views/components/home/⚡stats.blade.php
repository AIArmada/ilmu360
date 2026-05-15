<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function events(): int
    {
        return Cache::remember('home.stats.events.upcoming', 300, function () {
            // Only count approved events starting from now onwards
            return Event::active()
                ->where('starts_at', '>=', now())
                ->count('id');
        });
    }

    #[Computed]
    public function speakers(): int
    {
        return Cache::remember('home.stats.speakers.upcoming', 300, function () {
            return Speaker::active()
                ->whereHas('events', function ($query) {
                    $query->active()
                        ->where('starts_at', '>=', now());
                })->count('id');
        });
    }

    #[Computed]
    public function institutions(): int
    {
        return Cache::remember('home.stats.institutions.upcoming', 300, function () {
            return Institution::active()
                ->whereHas('events', function ($query) {
                    $query->active()
                        ->where('starts_at', '>=', now());
                })->count('id');
        });
    }
};
?>

@placeholder
<div class="grid grid-cols-3 gap-4 max-w-lg mx-auto">
    <div class="text-center">
        <div class="h-10 w-16 bg-white/20 rounded animate-pulse mx-auto"></div>
        <div class="text-xs sm:text-sm text-slate-400 leading-none">{{ __('Majlis Akan Datang') }}</div>
    </div>
    <div class="text-center border-x border-white/10">
        <div class="h-10 w-16 bg-white/20 rounded animate-pulse mx-auto"></div>
        <div class="text-xs sm:text-sm text-slate-400 leading-none">{{ __('Penceramah') }}</div>
    </div>
    <div class="text-center">
        <div class="h-10 w-16 bg-white/20 rounded animate-pulse mx-auto"></div>
        <div class="text-xs sm:text-sm text-slate-400 leading-none">{{ __('Institusi') }}</div>
    </div>
</div>
@endplaceholder

<div class="grid grid-cols-3 gap-4 max-w-lg mx-auto">
    <div class="text-center">
        <div class="text-3xl sm:text-4xl font-bold leading-none text-white">{{ number_format($this->events) }}</div>
        <div class="text-xs sm:text-sm text-slate-400 leading-none">{{ __('Majlis Akan Datang') }}</div>
    </div>
    <div class="text-center border-x border-white/10">
        <div class="text-3xl sm:text-4xl font-bold leading-none text-white">{{ number_format($this->speakers) }}</div>
        <div class="text-xs sm:text-sm text-slate-400 leading-none">{{ __('Penceramah') }}</div>
    </div>
    <div class="text-center">
        <div class="text-3xl sm:text-4xl font-bold leading-none text-white">{{ number_format($this->institutions) }}
        </div>
        <div class="text-xs sm:text-sm text-slate-400 leading-none">{{ __('Institusi') }}</div>
    </div>
</div>