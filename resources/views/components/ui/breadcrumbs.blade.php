@props(['items' => []])

<nav {{ $attributes->merge(['aria-label' => __('Jejak navigasi'), 'data-ui' => 'public-breadcrumbs']) }}>
    <ol class="flex items-center gap-1">
        @foreach($items as $item)
            @if(! $loop->first)
                <li class="shrink-0 text-slate-300" aria-hidden="true">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                    </svg>
                </li>
            @endif

            @php
                $icon = $item['icon'] ?? 'collection';
                $isCurrent = $loop->last;
                $url = $item['url'] ?? null;
                $showLabel = $item['show_label'] ?? false;
            @endphp

            <li>
                @if(filled($url))
                    <a
                        href="{{ $url }}"
                        wire:navigate
                        aria-label="{{ $item['label'] }}"
                        title="{{ $item['label'] }}"
                        class="group inline-flex size-8 items-center justify-center rounded-full border border-slate-200/80 bg-white/75 px-2 text-slate-500 shadow-sm backdrop-blur transition hover:border-emerald-200 hover:bg-white hover:text-emerald-800 {{ $showLabel ? 'w-auto gap-1.5 text-xs font-semibold' : '' }}"
                    >
                        @if($showLabel)
                            <span>{{ $item['label'] }}</span>
                        @else
                            <x-ui.breadcrumb-icon :icon="$icon" />
                        @endif
                    </a>
                @else
                    <span
                        aria-label="{{ $item['label'] }}"
                        title="{{ $item['label'] }}"
                        class="inline-grid size-8 place-items-center rounded-full border border-emerald-100 bg-emerald-50/90 text-emerald-900 shadow-sm"
                        @if($isCurrent) aria-current="page" @endif
                    >
                        <x-ui.breadcrumb-icon :icon="$icon" />
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
