@props([
    'shareData' => [],
    'shareLinks' => [],
    'copyMessage' => __('Link copied to clipboard!'),
    'copyPrompt' => __('Copy this link:'),
])

<div
    x-data="{
        copied: false,
        shareData: @js($shareData),
        trackEndpoint: @js(route('dawah-share.track')),
        providerQueryParameter: @js(config('dawah-share.provider_query_parameter', 'channel')),
        attributedShareData: null,
        async resolveShareData() {
            if (this.attributedShareData) {
                return this.attributedShareData;
            }

            const params = new URLSearchParams({
                url: this.shareData.sourceUrl,
                text: this.shareData.shareText,
                title: this.shareData.fallbackTitle,
            });
            const response = await fetch(`${this.shareData.payloadEndpoint}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                return this.shareData;
            }

            const payload = await response.json();
            this.attributedShareData = {
                ...this.shareData,
                url: payload.url,
                tracking_token: payload.tracking_token ?? null,
            };

            return this.attributedShareData;
        },
        async sharePayloadForChannel(provider = null) {
            const shareData = await this.resolveShareData();

            if (! shareData || ! provider || ! shareData.tracking_token) {
                return shareData;
            }

            try {
                const shareUrl = new URL(shareData.url, window.location.origin);
                shareUrl.searchParams.set(this.providerQueryParameter, provider);

                return {
                    ...shareData,
                    url: shareUrl.toString(),
                };
            } catch (error) {
                return shareData;
            }
        },
        async trackShare(provider) {
            const shareData = await this.resolveShareData();

            if (! shareData?.tracking_token) {
                return;
            }

            const csrfToken = document.querySelector('meta[name=csrf-token]')?.content;

            if (! csrfToken) {
                return;
            }

            await fetch(this.trackEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    provider,
                    tracking_token: shareData.tracking_token,
                }),
            });
        },
        async nativeShare() {
            const shareData = await this.sharePayloadForChannel('native_share');

            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                    await this.trackShare('native_share');
                } catch (error) {
                }

                return;
            }

            await this.copyLink();
        },
        async copyLink(shouldTrack = true, provider = 'copy_link') {
            const shareData = await this.sharePayloadForChannel(provider);

            if (!navigator.clipboard) {
                window.prompt(@js($copyPrompt), shareData.url);

                if (shouldTrack) {
                    await this.trackShare(provider);
                }

                return;
            }

            navigator.clipboard.writeText(shareData.url).then(async () => {
                if (shouldTrack) {
                    await this.trackShare(provider);
                }

                this.copied = true;
                setTimeout(() => this.copied = false, 2200);
            }, async () => {
                window.prompt(@js($copyPrompt), shareData.url);

                if (shouldTrack) {
                    await this.trackShare(provider);
                }
            });
        },
    }"
    class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm"
>
    <div class="flex flex-col gap-5">
        <div class="grid grid-cols-2 gap-3">
            <button
                type="button"
                @click="nativeShare()"
                class="inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white transition hover:bg-emerald-700"
            >
                {{ __('Share') }}
            </button>
            <button
                type="button"
                @click="copyLink()"
                class="inline-flex items-center justify-center gap-2 rounded-2xl border-2 border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 transition hover:border-emerald-500 hover:text-emerald-700"
            >
                {{ __('Copy Link') }}
            </button>
        </div>

        <div
            x-show="copied"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="flex items-center justify-center gap-2 rounded-xl bg-emerald-50 py-2 text-sm font-bold text-emerald-600"
        >
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            {{ $copyMessage }}
        </div>

        <div>
            <p class="mb-4 flex items-center justify-center gap-1.5 text-center text-xs font-bold uppercase tracking-widest text-slate-400">
                <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                </svg>
                <span>{{ __('Or share via') }}</span>
            </p>
            <div class="grid grid-cols-4 gap-3">
                <a href="{{ $shareLinks['whatsapp'] ?? '#' }}" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp" class="group inline-flex h-14 items-center justify-center rounded-2xl p-1 transition hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#25D366]/20">
                    <img src="{{ asset('storage/social-media-icons/whatsapp.svg') }}" alt="" class="h-9 w-9 object-contain transition duration-200 group-hover:scale-110 sm:h-10 sm:w-10" loading="lazy">
                </a>
                <a href="{{ $shareLinks['telegram'] ?? '#' }}" target="_blank" rel="noopener" title="Telegram" aria-label="Telegram" class="group inline-flex h-14 items-center justify-center rounded-2xl p-1 transition hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#0088cc]/20">
                    <img src="{{ asset('storage/social-media-icons/telegram.svg') }}" alt="" class="h-9 w-9 object-contain transition duration-200 group-hover:scale-110 sm:h-10 sm:w-10" loading="lazy">
                </a>
                <a href="{{ $shareLinks['threads'] ?? '#' }}" target="_blank" rel="noopener" title="Threads" aria-label="Threads" class="group inline-flex h-14 items-center justify-center rounded-2xl p-1 transition hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-900/15">
                    <img src="{{ asset('storage/social-media-icons/threads.svg') }}" alt="" class="h-9 w-9 object-contain transition duration-200 group-hover:scale-110 sm:h-10 sm:w-10" loading="lazy">
                </a>
                <a href="{{ $shareLinks['facebook'] ?? '#' }}" target="_blank" rel="noopener" title="Facebook" aria-label="Facebook" class="group inline-flex h-14 items-center justify-center rounded-2xl p-1 transition hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#1877F2]/20">
                    <img src="{{ asset('storage/social-media-icons/facebook.svg') }}" alt="" class="h-9 w-9 object-contain transition duration-200 group-hover:scale-110 sm:h-10 sm:w-10" loading="lazy">
                </a>
                <a href="{{ $shareLinks['x'] ?? '#' }}" target="_blank" rel="noopener" title="X" aria-label="X" class="group inline-flex h-14 items-center justify-center rounded-2xl p-1 transition hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-900/15">
                    <img src="{{ asset('storage/social-media-icons/x.svg') }}" alt="" class="h-9 w-9 object-contain transition duration-200 group-hover:scale-110 sm:h-10 sm:w-10" loading="lazy">
                </a>
                <a href="{{ $shareLinks['instagram'] ?? '#' }}" target="_blank" rel="noopener" @click="copyLink(false, 'instagram')" title="Instagram" aria-label="Instagram" class="group inline-flex h-14 items-center justify-center rounded-2xl p-1 transition hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#E4405F]/20">
                    <img src="{{ asset('storage/social-media-icons/instagram.svg') }}" alt="" class="h-9 w-9 object-contain transition duration-200 group-hover:scale-110 sm:h-10 sm:w-10" loading="lazy">
                </a>
                <a href="{{ $shareLinks['tiktok'] ?? '#' }}" target="_blank" rel="noopener" @click="copyLink(false, 'tiktok')" title="TikTok" aria-label="TikTok" class="group inline-flex h-14 items-center justify-center rounded-2xl p-1 transition hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-900/15">
                    <img src="{{ asset('storage/social-media-icons/tiktok.svg') }}" alt="" class="h-9 w-9 object-contain transition duration-200 group-hover:scale-110 sm:h-10 sm:w-10" loading="lazy">
                </a>
                <a href="{{ $shareLinks['email'] ?? '#' }}" title="Email" aria-label="Email" class="group inline-flex h-14 items-center justify-center rounded-2xl p-1 transition hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15">
                    <img src="{{ asset('storage/social-media-icons/email.svg') }}" alt="" class="h-9 w-9 object-contain transition duration-200 group-hover:scale-110 sm:h-10 sm:w-10" loading="lazy">
                </a>
            </div>
        </div>
    </div>
</div>
