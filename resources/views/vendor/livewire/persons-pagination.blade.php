@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollToSelector = $scrollTo === true ? 'body' : $scrollTo;

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
        (() => {
            const targetSelector = '{$scrollToSelector}';
            const target = targetSelector === 'body'
                ? document.body
                : (\$el.closest(targetSelector) || document.querySelector(targetSelector));

            if (! target) {
                return;
            }

            const html = document.documentElement;
            const body = document.body;
            const previousHtmlScrollBehavior = html.style.scrollBehavior;
            const previousBodyScrollBehavior = body.style.scrollBehavior;

            html.style.scrollBehavior = 'auto';
            body.style.scrollBehavior = 'auto';

            const stickyHeader = document.querySelector('header.sticky, header[data-sticky-header]');
            const headerOffset = targetSelector === 'body'
                ? 0
                : ((stickyHeader?.getBoundingClientRect()?.height ?? 0) + 16);
            const targetTop = targetSelector === 'body'
                ? 0
                : Math.max(Math.round(target.getBoundingClientRect().top + window.scrollY - headerOffset), 0);

            window.scrollTo({ top: targetTop, left: 0, behavior: 'auto' });

            html.style.scrollBehavior = previousHtmlScrollBehavior;
            body.style.scrollBehavior = previousBodyScrollBehavior;
        })()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation">
            <div class="flex flex-col items-center gap-3 sm:relative sm:block">
                <div class="flex items-center justify-center gap-2">
                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <span
                            aria-disabled="true"
                            aria-label="{{ __('pagination.previous') }}"
                            class="inline-flex h-8 w-8 cursor-default items-center justify-center rounded-xl border border-white/90 bg-white text-slate-300 shadow-[0_8px_20px_-16px_rgba(15,23,42,0.5)]"
                        >
                            <span class="sr-only">{{ __('pagination.previous') }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    @else
                        <button
                            type="button"
                            wire:click="previousPage('{{ $paginator->getPageName() }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}"
                            wire:loading.attr="disabled"
                            dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after"
                            rel="prev"
                            aria-label="{{ __('pagination.previous') }}"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-white/90 bg-white text-slate-600 shadow-[0_8px_20px_-16px_rgba(15,23,42,0.5)] transition-colors hover:text-emerald-800 focus:z-10 focus:outline-none focus:ring-2 focus:ring-emerald-700/20 active:bg-slate-50 disabled:cursor-wait disabled:opacity-60"
                        >
                            <span class="sr-only">{{ __('pagination.previous') }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span aria-disabled="true" class="inline-flex h-8 min-w-8 items-center justify-center rounded-xl border border-white/90 bg-white px-2 text-xs font-medium text-slate-400 shadow-[0_8px_20px_-16px_rgba(15,23,42,0.5)]">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                                    @if ($page == $paginator->currentPage())
                                        <span aria-current="page">
                                            <span class="inline-flex h-8 min-w-8 items-center justify-center rounded-xl border border-emerald-800 bg-emerald-800 px-2 text-xs font-semibold text-white shadow-[0_10px_18px_-12px_rgba(0,77,53,0.9)]">{{ $page }}</span>
                                        </span>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                            x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                            class="inline-flex h-8 min-w-8 items-center justify-center rounded-xl border border-white/90 bg-white px-2 text-xs font-medium text-slate-700 shadow-[0_8px_20px_-16px_rgba(15,23,42,0.5)] transition-colors hover:text-emerald-800 focus:z-10 focus:outline-none focus:ring-2 focus:ring-emerald-700/20 active:bg-slate-50 disabled:cursor-wait disabled:opacity-60"
                                            aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ $page }}
                                        </button>
                                    @endif
                                </span>
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <button
                            type="button"
                            wire:click="nextPage('{{ $paginator->getPageName() }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}"
                            wire:loading.attr="disabled"
                            dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after"
                            rel="next"
                            aria-label="{{ __('pagination.next') }}"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-white/90 bg-white text-slate-600 shadow-[0_8px_20px_-16px_rgba(15,23,42,0.5)] transition-colors hover:text-emerald-800 focus:z-10 focus:outline-none focus:ring-2 focus:ring-emerald-700/20 active:bg-slate-50 disabled:cursor-wait disabled:opacity-60"
                        >
                            <span class="sr-only">{{ __('pagination.next') }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    @else
                        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}" class="inline-flex h-8 w-8 cursor-default items-center justify-center rounded-xl border border-white/90 bg-white text-slate-300 shadow-[0_8px_20px_-16px_rgba(15,23,42,0.5)]">
                            <span class="sr-only">{{ __('pagination.next') }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    @endif
                </div>

                <p class="text-center text-[0.68rem] leading-5 text-slate-500 sm:absolute sm:right-0 sm:top-1/2 sm:-translate-y-1/2 sm:text-right">
                    <span>{{ __('Showing') }}</span>
                    <span class="font-medium">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
                    <span>{{ __('of') }}</span>
                    <span class="font-medium">{{ $paginator->total() }}</span>
                    <span>{{ __('results') }}</span>
                </p>
            </div>
        </nav>
    @endif
</div>
