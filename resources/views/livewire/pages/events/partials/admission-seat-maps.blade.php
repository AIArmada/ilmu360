{{-- Shared seat map cards for the event, date and session pages.
     Expects $seatMapEntries and $seatMapScopeCount. --}}
@if($seatMapEntries->isNotEmpty())
    <ul class="mt-6 space-y-3">
        @foreach($seatMapEntries as $seatMapEntry)
            <li class="rounded-[14px] border border-[#f0e8d9] bg-[#faf6ed] p-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="font-heading text-[1.0625rem] leading-snug text-[#14342e]">{{ $seatMapEntry['seat_map']->name }}</p>
                        @if($seatMapScopeCount > 1 || $seatMapEntry['scope_type'] !== 'event')
                            <p class="mt-1 text-[12px] text-[#6b645e]">{{ __('Untuk') }}: {{ $seatMapEntry['scope_label'] }}</p>
                        @endif
                    </div>
                    <p class="shrink-0 text-right font-mono text-[11px] leading-5 text-[#6b645e]">
                        {{ $seatMapEntry['section_count'] }} {{ __('seksyen') }}
                        <span class="block">{{ $seatMapEntry['section_capacity'] }} {{ __('tempat') }}</span>
                    </p>
                </div>
                @if($seatMapEntry['seat_map']->sections->isNotEmpty())
                    <ul class="mt-3 flex flex-wrap gap-1.5">
                        @foreach($seatMapEntry['seat_map']->sections as $section)
                            <li class="rounded-full bg-white px-2.5 py-1 text-[12px] font-medium text-[#4c5b56] ring-1 ring-[#f0e8d9]">{{ $section->name }} · {{ $section->capacity }}</li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
@else
    <p class="mt-6 rounded-[14px] border border-dashed border-[#e6dccb] bg-[#faf6ed] px-4 py-3 text-[13px] leading-6 text-[#6b645e]">
        {{ __('Tempat duduk disokong untuk tiket ini, tetapi pelan tempat duduk belum dipaparkan.') }}
    </p>
@endif
