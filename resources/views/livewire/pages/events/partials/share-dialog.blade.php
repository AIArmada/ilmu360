<div
    x-show="shareOpen"
    x-cloak
    x-transition.opacity
    @keydown.escape.window="shareOpen = false"
    class="fixed inset-0 z-50 flex items-center justify-center bg-[#0b2b25]/75 p-4 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Kongsi majlis') }}"
>
    <div @click.away="shareOpen = false" class="max-h-[92vh] w-full max-w-md overflow-y-auto rounded-[22px] bg-[#fffdf9] shadow-2xl">
        <div class="relative bg-[#0b2b25] px-6 py-4 text-[#f7f3e8]">
            <p class="text-[11px] font-semibold uppercase tracking-[0.26em] text-[#f2c867]">{{ __('Sebarkan') }}</p>
            <button type="button" @click="shareOpen = false" class="absolute right-4 top-1/2 inline-flex size-9 -translate-y-1/2 items-center justify-center rounded-full text-[#f7f3e8]/70 transition duration-200 hover:bg-[#f7f3e8]/10 hover:text-[#f7f3e8] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c867]" aria-label="{{ __('Tutup') }}">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="p-5">
            <x-dawah-share-panel :share-data="[
                'title' => $shareTitle,
                'text' => \Illuminate\Support\Str::limit($event->description_text, 100),
                'url' => $shareUrl,
                'sourceUrl' => $shareUrl,
                'shareText' => trim($shareTitle.' - '.config('app.name')),
                'fallbackTitle' => $shareTitle,
                'payloadEndpoint' => route('dawah-share.payload'),
            ]" :share-links="$shareLinks" />
        </div>
    </div>
</div>
