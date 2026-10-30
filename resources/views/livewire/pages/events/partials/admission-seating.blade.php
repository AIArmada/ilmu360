{{-- Seating for the majlis: the seat map(s) attached to the scope the reader
     is on. Expects $detail plus $scopeId so the section id stays unique when a
     page renders more than one scope. --}}
@php
    $seatMapEntries = $detail->seatMapEntries();
    $requiresSeating = $detail->requiresSeating();
    $seatMapScopeCount = $seatMapEntries->pluck('scope_label')->unique()->count();
    $seatingSectionId = 'tempat-duduk-'.$scopeId;
@endphp
@if($seatMapEntries->isNotEmpty() || $requiresSeating)
    <section id="{{ $seatingSectionId }}" data-testid="event-seating-section" class="scroll-mt-8" aria-labelledby="{{ $seatingSectionId }}-title">
        <div class="mb-7">
            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Tempat duduk') }}</p>
            <h2 id="{{ $seatingSectionId }}-title" class="mt-2 font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Pelan tempat duduk') }}</h2>
        </div>

        <div class="mi-paper rounded-[20px] border border-[#e6dccb] bg-[#fffdf9] p-6 sm:p-8">
            @include('livewire.pages.events.partials.admission-seat-maps', [
                'seatMapEntries' => $seatMapEntries,
                'seatMapScopeCount' => $seatMapScopeCount,
            ])
        </div>
    </section>
@endif
