{{-- Shared capacity meters for the event, date and session pages. Expects $capacityEntries. --}}
@if($capacityEntries->isNotEmpty())
    <div class="mt-5 border-t border-slate-100 pt-5">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Kapasiti') }}</p>
        <div class="mt-3 space-y-3">
            @foreach($capacityEntries as $capacityEntry)
                @php
                    $capacityPercent = $capacityEntry['capacity'] > 0 ? min(100, (int) round(($capacityEntry['reserved'] / $capacityEntry['capacity']) * 100)) : 0;
                @endphp
                <div>
                    <div class="flex items-center justify-between gap-3 text-xs font-semibold text-slate-600">
                        <span>{{ $capacityEntry['scope_type'] === 'event' ? __('Keseluruhan majlis') : $capacityEntry['scope_label'] }}</span>
                        <span>{{ $capacityEntry['reserved'] }} / {{ $capacityEntry['capacity'] }}</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $capacityPercent >= 100 ? 'bg-rose-500' : 'bg-[#b27b1b]' }}" style="width: {{ $capacityPercent }}%"></div></div>
                </div>
            @endforeach
        </div>
    </div>
@endif
