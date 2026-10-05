{{-- Shared ticket card for the event, date and session pages. Expects $ticketEntry, $ticketScopeCount, $detail, $event, $eventActionsDisabled. --}}
@php
    $ticket = $ticketEntry['ticket'];
    $ticketIsRegistration = $detail->admissionKind($ticket) === 'registration';
    $ticketPrice = $ticket->price === null || (int) $ticket->price === 0
        ? __('Percuma')
        : \AIArmada\CommerceSupport\Support\MoneyFormatter::formatMinor((int) $ticket->price, $ticket->currency ?: 'MYR');
    $ticketSeatingMode = $ticket->seating_mode;
    $ticketSections = $ticket->seatingOptions
        ->map(fn ($option) => $option->relationLoaded('section') ? $option->section?->name : $detail->sectionNameFor($option->seat_section_id))
        ->filter()
        ->unique()
        ->values();
    $ticketCapacityRemaining = $detail->effectiveCapacityRemaining($ticketEntry['scope']);
    $ticketCapacityFull = $ticketCapacityRemaining !== null && $ticketCapacityRemaining <= 0;
@endphp
<article class="rounded-2xl border border-slate-200 bg-[#fbfaf7] p-4">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="font-semibold text-[#173c34]">{{ $ticket->name }}</h3>
            @if($ticketScopeCount > 1 || $ticketEntry['scope_type'] !== 'event')<p class="mt-1 text-xs font-semibold text-slate-500">{{ __('Untuk') }}: {{ $ticketEntry['scope_label'] }}</p>@endif
        </div>
        <span class="shrink-0 text-sm font-bold text-emerald-900">{{ $ticketPrice }}</span>
    </div>
    @if(filled($ticket->description))<p class="mt-2 text-sm leading-6 text-slate-600">{{ $ticket->description }}</p>@endif
    <div class="mt-3 flex flex-wrap gap-x-3 gap-y-2 text-xs font-semibold text-slate-500">
        @if($ticketSeatingMode && $ticketSeatingMode->requiresAllocation())<span>{{ $ticketSeatingMode->label() }}</span>@endif
        @if($ticket->admits_quantity > 1)<span>{{ __('Masuk untuk') }} {{ $ticket->admits_quantity }} {{ __('orang') }}</span>@endif
        @if($ticket->min_quantity !== null || $ticket->max_quantity !== null) <span>{{ __('Kuantiti') }} {{ $ticket->min_quantity ?? 1 }}@if($ticket->max_quantity !== null) — {{ $ticket->max_quantity }}@endif</span>@endif
        @if($ticketEntry['inventory_configured'])<span class="{{ $ticketEntry['inventory_available'] === 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $ticketEntry['inventory_available'] === 0 ? __('Habis') : $ticketEntry['inventory_available'].' '.__('tersedia') }}</span>@endif
        @if($ticketCapacityRemaining !== null)<span class="{{ $ticketCapacityFull ? 'text-rose-600' : 'text-slate-500' }}">{{ $ticketCapacityFull ? __('Tempat penuh') : __('Tinggal :count tempat', ['count' => $ticketCapacityRemaining]) }}</span>@endif
    </div>
    @if($ticketSections->isNotEmpty())<p class="mt-3 text-xs font-semibold text-slate-500">{{ __('Seksyen') }}: {{ $ticketSections->implode(', ') }}</p>@endif
    @if($ticket->sales_starts_at || $ticket->sales_ends_at)<p class="mt-3 border-t border-slate-200 pt-3 text-xs text-slate-500">@if($ticket->sales_starts_at){{ __('Dibuka') }} {{ \App\Support\Timezone\UserDateTimeFormatter::format($ticket->sales_starts_at, 'j M, h:i A') }}@endif @if($ticket->sales_ends_at) · {{ __('Tutup') }} {{ \App\Support\Timezone\UserDateTimeFormatter::format($ticket->sales_ends_at, 'j M, h:i A') }}@endif</p>@endif
    @php
        $ticketSalesOpen = $detail->ticketSalesOpen($ticket);
        $ticketHasStock = ! $ticketEntry['inventory_configured'] || $ticketEntry['inventory_available'] === null || $ticketEntry['inventory_available'] > 0;
        $ticketCanCheckout = ! $eventActionsDisabled && ! $detail->scopeActionsDisabled($ticketEntry['scope'])
            && $ticket->status === 'active'
            && $ticket->isPubliclyVisible()
            && $ticketSalesOpen
            && $ticketHasStock
            && ! $ticketCapacityFull
            && ((int) ($ticket->price ?? 0) === 0 || \App\Support\Commerce\EventCommerceModes::publicPaidCheckoutEnabled());
    @endphp
    @if($ticketCanCheckout)
        <a href="{{ route('events.checkout', ['event' => $event, 'ticket' => $ticket->getKey()]) }}"
            data-signal-event="commerce.event_checkout_started"
            data-signal-category="commerce"
            data-signal-component="event_detail_ticket"
            data-signal-control="buy_ticket"
            data-signal-entity-type="ticket_type"
            data-signal-entity-id="{{ $ticket->getKey() }}"
            data-signal-props='@json(['scope_type' => $ticketEntry['scope_type'], 'price' => (int) ($ticket->price ?? 0)])'
            class="mt-4 inline-flex w-full items-center justify-center rounded-xl bg-[#173c34] px-4 py-3 text-sm font-bold text-white transition hover:bg-[#21594c] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
            {{ (int) ($ticket->price ?? 0) > 0 ? __('Beli tiket') : ($ticketIsRegistration ? __('Daftar kehadiran') : __('Dapatkan tiket percuma')) }}
        </a>
    @elseif($detail->scopeActionsDisabled($ticketEntry['scope']))
        <p class="mt-4 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-600">{{ __('Pendaftaran ditutup kerana status program.') }}</p>
    @elseif($ticket->sales_starts_at && $ticket->sales_starts_at->isFuture())
        <p class="mt-4 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-600">{{ __('Pendaftaran belum dibuka.') }}</p>
    @elseif($ticket->sales_ends_at && $ticket->sales_ends_at->isPast())
        <p class="mt-4 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-600">{{ __('Jualan tiket telah ditutup.') }}</p>
    @elseif($ticketCapacityFull)
        <p class="mt-4 rounded-xl bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ __('Tempat penuh buat masa ini.') }}</p>
    @elseif($ticketEntry['inventory_configured'] && $ticketEntry['inventory_available'] === 0)
        <p class="mt-4 rounded-xl bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ __('Habis') }}</p>
    @endif
</article>
