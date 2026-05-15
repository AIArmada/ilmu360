<?php

use App\Models\Event;
use App\Support\Timezone\UserDateTimeFormatter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function upcomingDates(): Collection
    {
        $now = UserDateTimeFormatter::userNow();
        $startDate = $now->copy()->startOfDay();
        $endDate = $now->copy()->addDays(6)->endOfDay();

        $eventCounts = Event::query()
            ->active()
            ->whereBetween('starts_at', [$startDate->copy()->utc(), $endDate->copy()->utc()])
            ->get(['starts_at'])
            ->countBy(fn (Event $event): string => UserDateTimeFormatter::format($event->starts_at, 'Y-m-d'));

        $dates = collect();

        for ($i = 0; $i < 7; $i++) {
            $date = $now->copy()->addDays($i);
            $dateKey = $date->format('Y-m-d');

            $dates->push([
                'date' => $date,
                'day_name' => $i === 0 ? __('Hari Ini') : ($i === 1 ? __('Esok') : $date->translatedFormat('l')),
                'day_short' => $date->format('d'),
                'month_short' => $date->translatedFormat('M'),
                'count' => $eventCounts[$dateKey] ?? 0,
            ]);
        }

        return $dates;
    }
};
?>

@placeholder
<div class="flex flex-wrap items-center justify-center gap-1.5">
    @for($i = 0; $i < 7; $i++)
        <div class="flex flex-col items-center py-1 px-3 rounded-2xl border-2 border-slate-100 min-w-[85px] animate-pulse">
            <span class="w-12 h-3 bg-slate-200 rounded mb-1"></span>
            <span class="w-8 h-6 bg-slate-200 rounded"></span>
            <span class="w-8 h-3 bg-slate-200 rounded"></span>
        </div>
    @endfor
</div>
@endplaceholder

<div class="flex flex-wrap items-center justify-center gap-1.5">
    @foreach($this->upcomingDates as $dateItem)
        <a wire:key="date-{{ $dateItem['date']->format('Y-m-d') }}"
            href="{{ route('events.index', ['starts_after' => $dateItem['date']->format('Y-m-d'), 'starts_before' => $dateItem['date']->format('Y-m-d'), 'time_scope' => 'all']) }}" wire:navigate
            class="group flex flex-col items-center py-1 px-3 rounded-2xl border-2 border-slate-100 hover:border-emerald-500 hover:bg-emerald-50 transition-all min-w-[85px] {{ $loop->first ? 'bg-emerald-50 border-emerald-500' : '' }}">
            <span
                class="text-xs font-semibold text-slate-400 uppercase tracking-wide group-hover:text-emerald-600 {{ $loop->first ? 'text-emerald-600' : '' }}">
                {{ $dateItem['day_name'] }}
            </span>
            <span
                class="text-2xl font-bold text-slate-900 group-hover:text-emerald-600 {{ $loop->first ? 'text-emerald-600' : '' }}">
                {{ $dateItem['day_short'] }}
            </span>
            <span class="text-xs text-slate-400">{{ $dateItem['month_short'] }}</span>
            @if($dateItem['count'] > 0)
                <span
                    class="mt-1 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">
                    {{ $dateItem['count'] }}
                </span>
            @endif
        </a>
    @endforeach
</div>