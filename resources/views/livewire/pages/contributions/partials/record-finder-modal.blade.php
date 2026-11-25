@php
    $finderCopy = $this->finderModeCopy;
    $finderResults = $this->finderResults;
@endphp

<flux:modal
    wire:model="showRecordFinder"
    name="contributions-record-finder"
    class="max-w-2xl"
>
    <div class="space-y-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ $finderCopy['title'] }}</flux:heading>
                <flux:subheading>{{ $finderCopy['hint'] }}</flux:subheading>
            </div>
            <button type="button" wire:click="closeRecordFinder" wire:loading.attr="disabled" wire:target="closeRecordFinder"
                aria-label="{{ __('Close') }}"
                class="flex size-11 shrink-0 items-center justify-center rounded-full border border-[#8c867e]/60 bg-white text-[#17191c] transition-colors hover:border-[#047857] hover:text-[#047857] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Subject switcher --}}
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Record type') }}">
            @foreach($this->finderSubjectOptions as $subjectValue => $subjectLabel)
                <button type="button" wire:key="finder-type-{{ $subjectValue }}"
                    wire:click="setFinderSubjectType('{{ $subjectValue }}')"
                    wire:loading.attr="disabled" wire:target="setFinderSubjectType"
                    aria-pressed="{{ $this->finderSubjectType === $subjectValue ? 'true' : 'false' }}"
                    @class([
                        'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-4 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]',
                        'border-[#047857] bg-white font-bold text-[#065f46]' => $this->finderSubjectType === $subjectValue,
                        'border-[#8c867e]/50 bg-white/70 font-semibold text-[#3f3b37] hover:border-[#047857] hover:text-[#047857]' => $this->finderSubjectType !== $subjectValue,
                    ])>
                    @if($this->finderSubjectType === $subjectValue)
                        <svg class="size-3.5 text-[#047857]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    @endif
                    {{ $subjectLabel }}
                </button>
            @endforeach
        </div>

        {{-- Live search --}}
        <label class="relative block">
            <span class="sr-only">{{ __('Search records') }}</span>
            <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#8c867e]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
            </svg>
            <input type="search" wire:model.live.debounce.250ms="finderQuery"
                placeholder="{{ __('Search records') }}"
                class="{{ $inputClass }} pl-10" />
        </label>

        <div class="relative min-h-24" wire:loading.class="opacity-60" wire:loading.attr="aria-busy"
            wire:target="finderQuery,setFinderSubjectType,openRecordFinder">
            <div wire:loading.delay.short wire:target="finderQuery,setFinderSubjectType"
                class="absolute right-3 top-1 flex items-center gap-2 rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-[#6b645e] shadow-sm">
                <svg class="size-3.5 animate-spin text-[#047857]" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
                </svg>
                {{ __('Searching…') }}
            </div>

            <ul class="space-y-2">
                @forelse($finderResults as $result)
                    <li wire:key="finder-result-{{ $loop->index }}-{{ md5($result['url']) }}">
                        <a href="{{ $result['url'] }}" wire:navigate
                            data-signal-event="contribution.hub_record_selected"
                            data-signal-category="contribution"
                            data-signal-component="contributions_hub"
                            data-signal-props='@json(['mode' => $this->finderMode, 'subject_type' => $this->finderSubjectType])'
                            class="flex min-h-11 items-center justify-between gap-3 rounded-2xl border border-[#e5ded8] bg-white px-4 py-3 transition-colors hover:border-[#047857] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.99]">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-[#17191c]">{{ $result['title'] }}</span>
                                @if(filled($result['subtitle']))
                                    <span class="mt-0.5 block truncate text-xs text-[#6b645e]">{{ $result['subtitle'] }}</span>
                                @endif
                            </span>
                            <svg class="size-4 shrink-0 text-[#8c867e]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </a>
                    </li>
                @empty
                    <li class="rounded-2xl border border-dashed border-[#e5ded8] px-4 py-8 text-center">
                        <p class="text-sm font-semibold text-[#17191c]">{{ __('No matching records found.') }}</p>
                        <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Try a different name, or browse the directory directly.') }}</p>
                        <a href="{{ $this->finderBrowseUrl() }}" wire:navigate
                            class="mt-4 inline-flex min-h-11 items-center justify-center rounded-full border border-[#8c867e]/60 bg-white px-4 text-sm font-semibold text-[#17191c] transition-colors hover:border-[#047857] hover:text-[#047857] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]">
                            {{ __('Browse the directory') }}
                        </a>
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</flux:modal>
