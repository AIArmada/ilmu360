{{-- Shared seat map cards for the event, date and session pages. Expects $seatMapEntries and $seatMapScopeCount. --}}
@if($seatMapEntries->isNotEmpty())
    <div class="mt-5 space-y-3">
        @foreach($seatMapEntries as $seatMapEntry)
            <div class="rounded-xl border border-slate-200 bg-[#fbfaf7] p-4">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="font-semibold text-[#173c34]">{{ $seatMapEntry['seat_map']->name }}</p>@if($seatMapScopeCount > 1 || $seatMapEntry['scope_type'] !== 'event')<p class="mt-1 text-xs text-slate-500">{{ __('Untuk') }}: {{ $seatMapEntry['scope_label'] }}</p>@endif</div>
                    <span class="shrink-0 text-right text-xs font-semibold text-slate-500">{{ $seatMapEntry['section_count'] }} {{ __('seksyen') }}<span class="block mt-1">{{ $seatMapEntry['section_capacity'] }} {{ __('tempat') }}</span></span>
                </div>
                @if($seatMapEntry['seat_map']->sections->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($seatMapEntry['seat_map']->sections as $section)<span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $section->name }} · {{ $section->capacity }}</span>@endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@else
    <p class="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">{{ __('Tempat duduk disokong untuk tiket ini, tetapi pelan tempat duduk belum dipaparkan.') }}</p>
@endif
