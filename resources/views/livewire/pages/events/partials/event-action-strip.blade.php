{{-- Personal actions a reader takes after deciding to come: attend, save,
     check in, plus the calendar and admin shortcuts. Kept out of the hero so
     the hero still carries exactly one filled button. Shared by the date page
     and the multi-date listing. Sharing lives in the hero and the closing
     band, so it is not repeated here.

     Expects: $event, $calendarLinks, $eventActionsDisabled, $checkInOffered,
     $canEditEvent. --}}
<div class="border-b border-[#e6dccb] bg-[#fffdf9]">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-2.5 px-5 py-3 sm:px-8 lg:px-12">
        <div class="flex flex-wrap items-center gap-2">
            {{-- "Akan Hadir" is a promise to turn up; offering it for a majlis
                 that has already finished would be a lie. --}}
            @if(! $eventActionsDisabled && $this->eventTimeStatus !== 'past')
                <button
                    type="button"
                    wire:click="toggleGoing"
                    data-signal-event="engagement.event_going_clicked" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="going" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-[13px] font-semibold transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e] {{ $this->isGoing ? 'border-[#14342e] bg-[#14342e] text-[#fdfaf2] hover:bg-[#1d4a41]' : 'border-[#e6dccb] bg-white text-[#14342e] hover:border-[#c9962f]/50 hover:bg-[#f8f3e8]' }}"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ $this->isGoing ? __('Hadir') : __('Akan Hadir') }}
                    @if($this->goingCount > 0)
                        <span class="rounded-full px-1.5 py-0.5 font-mono text-[11px] {{ $this->isGoing ? 'bg-[#f7f3e8]/20 text-[#fdfaf2]' : 'bg-[#f3efe6] text-[#6b645e]' }}">{{ $this->goingCount }}</span>
                    @endif
                </button>
            @endif

            <button
                type="button"
                wire:click="toggleSave"
                    data-signal-event="engagement.event_save_clicked" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="save" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-[13px] font-semibold transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e] {{ $this->isSaved ? 'border-[#14342e] bg-[#14342e] text-[#fdfaf2] hover:bg-[#1d4a41]' : 'border-[#e6dccb] bg-white text-[#14342e] hover:border-[#c9962f]/50 hover:bg-[#f8f3e8]' }}"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="{{ $this->isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" /></svg>
                {{ $this->isSaved ? __('Disimpan') : __('Simpan') }}
            </button>

            @if($checkInOffered)
                @php
                    $checkInAvailable = (bool) ($this->checkInState['available'] ?? false);
                    $checkInBlocked = auth()->check() && ! $checkInAvailable && ! $this->isCheckedIn;
                @endphp
                <button
                    type="button"
                    wire:click="checkIn"
                    data-signal-event="engagement.event_check_in_clicked" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="check_in" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}"
                    wire:loading.attr="disabled"
                    @disabled($checkInBlocked)
                    @if($checkInBlocked && filled($this->checkInState['reason'] ?? null)) title="{{ $this->checkInState['reason'] }}" @endif
                    class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-[13px] font-semibold transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e] {{ $this->isCheckedIn ? 'border-[#2f5d43] bg-[#eef4ee] text-[#2f5d43]' : ($checkInBlocked ? 'cursor-not-allowed border-[#eee7d8] bg-[#faf6ed] text-[#a49a8a]' : 'border-[#e6dccb] bg-white text-[#14342e] hover:border-[#2f5d43]/40 hover:bg-[#f2f7f2] active:scale-[0.98]') }}"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                    {{ $this->isCheckedIn ? __('Sudah check-in') : __('Check-in') }}
                </button>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if($calendarLinks !== [])
                <div class="relative" x-data="{ calendarOpen: false }">
                    <button type="button" @click="calendarOpen = ! calendarOpen" data-signal-event="engagement.calendar_opened" data-signal-category="engagement" data-signal-component="event_detail_actions" data-signal-control="calendar" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="inline-flex items-center gap-2 rounded-full border border-[#e6dccb] bg-white px-4 py-2 text-[13px] font-semibold text-[#14342e] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-[#c9962f]/50 hover:bg-[#f8f3e8] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 4h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5v12a2 2 0 002 2z" /></svg>
                        <span>{{ __('Kalendar') }}</span>
                        <svg class="size-3 text-[#8b8272]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <div x-show="calendarOpen" x-cloak @click.away="calendarOpen = false" class="absolute right-0 top-full z-30 mt-2 w-56 overflow-hidden rounded-[14px] border border-[#e6dccb] bg-[#fffdf9] p-1.5 shadow-[0_24px_50px_-24px_rgba(20,52,46,0.45)]">
                        @foreach(['google' => 'Google Calendar', 'ics' => 'Apple / iCal (.ics)', 'outlook' => 'Outlook.com', 'office365' => 'Office 365', 'yahoo' => 'Yahoo Calendar'] as $calendarKey => $calendarLabel)
                            @if(filled($calendarLinks[$calendarKey] ?? null))
                                <a href="{{ $calendarLinks[$calendarKey] }}" @if($calendarKey === 'ics') download @else target="_blank" rel="noopener" @endif class="block rounded-[10px] px-3 py-2 text-[13px] font-medium text-[#14342e] transition-colors duration-200 hover:bg-[#f3efe6]">{{ $calendarLabel }}</a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if($canEditEvent)
                <a href="{{ \AIArmada\FilamentEvents\Resources\EventResource::getUrl('edit', ['record' => $event], panel: 'admin') }}" target="_blank" data-testid="event-admin-edit-button" class="inline-flex items-center gap-2 rounded-full border border-[#e6d3a3] bg-[#fdf7e7] px-4 py-2 text-[13px] font-semibold text-[#7a5410] transition duration-300 hover:border-[#c9962f] hover:bg-[#fbf0d6] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#c9962f]">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                    {{ __('Edit') }}
                </a>
            @endif
        </div>
    </div>
</div>
