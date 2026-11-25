@php
    $myAppFeedback = $this->myAppFeedback;
@endphp

<section aria-labelledby="contributions-feedback-heading">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 id="contributions-feedback-heading" class="font-heading text-2xl font-semibold text-[#17191c]">
                {{ __('App Feedback') }}
            </h2>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-[#3f3b37]">
                {{ __('Bugs, feature requests and performance notes you sent us, with every moderator reply.') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold tabular-nums text-[#065f46]">{{ $myAppFeedback->total() }}</span>
            @if($blocked)
                <button type="button" disabled class="{{ $pillEmeraldOutline }}">
                    {{ __('Feedback paused') }}
                </button>
            @else
                <a href="{{ route('app-feedback.index', ['dari' => '/sumbangan']) }}" wire:navigate class="{{ $pillEmeraldOutline }}">
                    {{ __('Send feedback') }}
                </a>
            @endif
        </div>
    </div>

    <div class="mt-5 space-y-3">
        @forelse($myAppFeedback as $feedback)
            @php
                $createdAt = $presenter->formatDateTime($feedback->createdAt);
            @endphp

            <article wire:key="feedback-{{ $feedback->id }}" class="{{ $cardClass }} p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="{{ $toneBadge($presenter->statusTone($feedback->status)) }}">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                                {{ $feedback->statusLabel }}
                            </span>
                            <span class="rounded-full bg-[#f4f1ec] px-2.5 py-1 text-xs font-semibold text-[#6b645e]">{{ $feedback->categoryLabel }}</span>
                            @if($feedback->visibleMessagesCount > 0)
                                <span class="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-800">
                                    {{ trans_choice(':count reply|:count replies', $feedback->visibleMessagesCount, ['count' => $feedback->visibleMessagesCount]) }}
                                </span>
                            @endif
                        </div>

                        <h3 class="text-base font-semibold text-[#17191c]">{{ $feedback->title }}</h3>

                        @if($createdAt !== null)
                            <p class="text-xs tabular-nums text-[#6b645e]">{{ __('Submitted :date', ['date' => $createdAt]) }}</p>
                        @endif

                        <p class="text-sm leading-6 text-[#3f3b37]">{{ $feedback->description }}</p>

                        @if(filled($feedback->resolutionNote))
                            <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">
                                <span class="font-semibold text-[#17191c]">{{ __('Resolution') }}:</span>
                                {{ $feedback->resolutionNote }}
                            </p>
                        @endif

                        @if(filled($feedback->pageUrl))
                            <p class="truncate text-xs text-[#6b645e]">{{ __('Reported from :page', ['page' => $feedback->pageUrl]) }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-stretch">
                        <button type="button"
                            wire:click="inspectItem('feedback:{{ $feedback->id }}')"
                            wire:loading.attr="disabled" wire:target="inspectItem"
                            class="{{ $pillEmeraldOutline }}">
                            {{ __('View details') }}
                        </button>
                        <a href="{{ $feedback->threadUrl }}" wire:navigate class="{{ $pillSecondary }}">
                            {{ __('Open thread') }}
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </a>
                    </div>
                </div>
            </article>
        @empty
            <div class="{{ $cardClass }} px-5 py-14 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-50 text-[#065f46]" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                    </svg>
                </div>
                <p class="mt-4 text-base font-semibold text-[#17191c]">{{ __('No app feedback yet.') }}</p>
                <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Tell us about a bug, an idea or something that feels slow.') }}</p>
                @if($blocked)
                    <button type="button" disabled class="{{ $pillEmeraldOutline }} mt-5">
                        {{ __('Feedback paused') }}
                    </button>
                @else
                    <a href="{{ route('app-feedback.index', ['dari' => '/sumbangan']) }}" wire:navigate class="{{ $pillEmeraldOutline }} mt-5">
                        {{ __('Send feedback') }}
                    </a>
                @endif
            </div>
        @endforelse
    </div>

    @if($myAppFeedback->hasPages())
        <div class="mt-6">{{ $myAppFeedback->links() }}</div>
    @endif
</section>
