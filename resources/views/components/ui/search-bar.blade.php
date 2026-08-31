{{--
    Shared public-directory search box.

    Used by the /majlis, /institusi and /penceramah hero sections so the three
    directories cannot drift apart visually or behaviourally.

    @param string      $inputId      Element id for the input (hint id defaults to "{inputId}-hint").
    @param string      $model        Livewire property bound with wire:model (supports dot paths, e.g. "filterData.search").
    @param mixed       $value        Current value; when filled the clear button is rendered.
    @param string      $placeholder  Input placeholder.
    @param string|null $label        Accessible label.
    @param bool        $labelVisible Render the label above the input instead of sr-only.
    @param int|null    $count        Result total to show beside a visible label.
    @param string|null $countLabel   Noun following the count.
    @param string      $clearMethod  Livewire method invoked by the clear button and the Escape key.
    @param string|null $clearLabel   Accessible label for the clear button.
    @param int         $debounce     Livewire live-debounce in milliseconds.
    @param string|null $hint         Screen-reader hint rendered below the input.
    @param string|null $hintId       Override for the hint element id.
    @param string|null $ariaControls Id of the results region the input controls.
    @param array       $clearAttributes Extra attributes rendered on the clear button (analytics markers).
    @param string      $width        Wrapper width classes.
--}}
@props([
    'inputId',
    'model' => 'search',
    'value' => null,
    'placeholder' => '',
    'label' => null,
    'labelVisible' => false,
    'count' => null,
    'countLabel' => null,
    'clearMethod' => 'clearSearch',
    'clearLabel' => null,
    'debounce' => 300,
    'hint' => null,
    'hintId' => null,
    'ariaControls' => null,
    'clearAttributes' => [],
    'width' => 'w-full',
])

@php
    $clearLabel ??= __('Clear search');
    $hintElementId = $hintId ?? ($hint !== null ? $inputId.'-hint' : null);
    $hasValue = filled($value);
@endphp

<div class="{{ $width }}">
    @if($labelVisible && filled($label))
        <div class="mb-2 flex items-center justify-between px-1 text-left">
            <label for="{{ $inputId }}" class="text-sm font-semibold text-slate-700">{{ $label }}</label>

            @if($count !== null)
                <span class="text-xs font-medium text-slate-500">{{ number_format((float) $count) }} {{ $countLabel }}</span>
            @endif
        </div>
    @elseif(filled($label))
        <label for="{{ $inputId }}" class="sr-only">{{ $label }}</label>
    @endif

    <div class="group relative" data-ui="search-bar">
        <input
            type="search"
            id="{{ $inputId }}"
            wire:model.live.debounce.{{ (int) $debounce }}ms="{{ $model }}"
            wire:keydown.escape="{{ $clearMethod }}"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            @if($ariaControls) aria-controls="{{ $ariaControls }}" @endif
            @if($hintElementId) aria-describedby="{{ $hintElementId }}" @endif
            {{
                $attributes->merge([
                    'class' => 'mi-search-input h-14 w-full rounded-2xl border-2 border-slate-200 bg-white pl-12 pr-14'
                        .' font-medium text-slate-900 shadow-lg shadow-slate-200/60 transition-all'
                        .' placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none'
                        .' focus:ring-4 focus:ring-emerald-500/10',
                ])
            }}
        >

        <svg
            class="pointer-events-none absolute left-4 top-1/2 h-6 w-6 -translate-y-1/2 text-slate-400 transition-colors group-focus-within:text-emerald-500"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>

        @if($hasValue)
            <button
                type="button"
                wire:click="{{ $clearMethod }}"
                wire:loading.attr="disabled"
                wire:target="{{ $clearMethod }}"
                aria-label="{{ $clearLabel }}"
                class="absolute right-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-500 shadow-sm transition hover:border-red-300 hover:bg-red-100 hover:text-red-600 focus:outline-none focus:ring-4 focus:ring-red-500/10"
                @foreach($clearAttributes as $attribute => $attributeValue)
                    {{ $attribute }}="{{ $attributeValue }}"
                @endforeach
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l8 8M14 6l-8 8" />
                </svg>
                <span class="sr-only">{{ $clearLabel }}</span>
            </button>
        @endif
    </div>

    @if($hint !== null)
        <p id="{{ $hintElementId }}" class="sr-only">{{ $hint }}</p>
    @endif
</div>
