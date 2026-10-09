@php
    $claimPromptUser = auth()->user();
    $claimPromptAvailable = $claimPromptUser instanceof \App\Models\User
        && app(\App\Services\ShareTracking\AffiliatesShareTrackingService::class)->claimPromptAvailable($claimPromptUser, request());
@endphp

@if ($claimPromptAvailable)
    <section aria-label="{{ __('Link past anonymous shares') }}"
        data-signal-event="share.claim_prompted" data-signal-category="share"
        data-signal-component="dashboard_claim_prompt" data-signal-control="claim_banner"
        class="mb-7 rounded-2xl border border-[#f2c867]/60 bg-[#fff8e6] p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm font-bold text-[#173c34]">{{ __('You shared pages before signing in') }}</p>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    {{ __('Link that anonymous history to your account so it counts toward your private impact.') }}
                </p>
            </div>
            <a href="{{ route('share.claim.show') }}"
                data-signal-event="share.claim_prompt_clicked" data-signal-category="share"
                data-signal-component="dashboard_claim_prompt" data-signal-control="claim_review"
                class="shrink-0 rounded-xl bg-[#173c34] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#21594c] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                {{ __('Review and link') }}
            </a>
        </div>
    </section>
@endif
