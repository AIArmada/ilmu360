@php($engagementEventId = $eventId ?? (string) $event->getKey())
    <div class="flex shrink-0 items-center gap-2">
        @if($cardGoingAvailable)
            <button
                type="button"
                wire:click.stop.prevent="toggleGoing('{{ $engagementEventId }}')"
                wire:loading.attr="disabled"
                wire:target="toggleGoing('{{ $engagementEventId }}')"
                data-going-icon="event"
                data-going-state="{{ $isGoing ? 'going' : 'not-going' }}"
                aria-label="{{ $isGoing ? __('Hadir') : __('Akan Hadir') }}"
                aria-pressed="{{ $isGoing ? 'true' : 'false' }}"
                title="{{ $isGoing ? __('Hadir') : __('Akan Hadir') }}"
                data-signal-event="engagement.event_going_clicked"
                data-signal-category="engagement"
                data-signal-component="{{ $signalComponent }}"
                data-signal-control="going"
                data-signal-entity-type="{{ $cardSignalEntityType }}"
                data-signal-entity-id="{{ $cardSignalEntityId }}"
                data-signal-props='@json(['currently_going' => $isGoing])'
                class="relative z-10 grid h-9 w-9 shrink-0 place-items-center rounded-xl border transition-colors duration-200 disabled:cursor-wait disabled:opacity-60 {{ $isGoing ? 'border-[#087f59] bg-[#087f59] text-white' : 'border-slate-200 bg-white text-[#087f59] group-hover:border-emerald-200 hover:border-emerald-200 hover:bg-emerald-100 hover:shadow-sm' }}"
            >
                <flux:icon.calendar-days class="size-4" :variant="$isGoing ? 'solid' : 'outline'" />
            </button>
        @endif
        <button type="button" wire:click.stop.prevent="toggleSave('{{ $engagementEventId }}')" wire:loading.attr="disabled" wire:target="toggleSave('{{ $engagementEventId }}')" data-save-icon="event" data-save-state="{{ $isSaved ? 'saved' : 'unsaved' }}" aria-label="{{ $isSaved ? __('Disimpan') : __('Simpan') }}" aria-pressed="{{ $isSaved ? 'true' : 'false' }}" title="{{ $isSaved ? __('Disimpan') : __('Simpan') }}" data-signal-event="engagement.event_save_clicked" data-signal-category="engagement" data-signal-component="{{ $signalComponent }}" data-signal-control="save" data-signal-entity-type="{{ $cardSignalEntityType }}" data-signal-entity-id="{{ $cardSignalEntityId }}" data-signal-props='@json(['currently_saved' => $isSaved])' class="relative z-10 grid h-9 w-9 shrink-0 place-items-center rounded-xl border transition-colors duration-200 disabled:cursor-wait disabled:opacity-60 {{ $isSaved ? 'border-[#087f59] bg-[#087f59] text-white' : 'border-slate-200 bg-white text-slate-400 group-hover:border-emerald-200 group-hover:text-[#087f59] hover:border-emerald-200 hover:bg-emerald-100 hover:text-[#087f59] hover:shadow-sm' }}"><flux:icon.bookmark class="size-4" :variant="$isSaved ? 'solid' : 'outline'" /></button>
    </div>
