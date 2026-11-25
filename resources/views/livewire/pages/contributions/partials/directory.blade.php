@php
    $directorySubmissions = $this->directorySubmissions;
@endphp

<section aria-labelledby="contributions-directory-heading">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 id="contributions-directory-heading" class="font-heading text-2xl font-semibold text-[#17191c]">
                {{ __('New Directory Records') }}
            </h2>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-[#3f3b37]">
                {{ __('Institutions, speakers and references you proposed. Pending requests can be cancelled here.') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold tabular-nums text-[#065f46]">{{ $directorySubmissions->total() }}</span>
            <a href="{{ route('contributions.submit-institution') }}" wire:navigate class="{{ $pillSecondary }}">{{ __('Institution') }}</a>
            <a href="{{ route('contributions.submit-person') }}" wire:navigate class="{{ $pillSecondary }}">{{ __('Speaker') }}</a>
            <a href="{{ route('contributions.submit-reference') }}" wire:navigate class="{{ $pillSecondary }}">{{ __('Reference') }}</a>
        </div>
    </div>

    <div class="mt-5 space-y-3">
        @forelse($directorySubmissions as $request)
            @php
                $status = $request->status->value;
                $submittedAt = $presenter->formatDateTime($request->created_at);
                $publicUrl = $presenter->requestPublicUrl($request);
                $summary = $presenter->requestSummaryLine($request);
            @endphp

            <article wire:key="directory-request-{{ $request->id }}" class="{{ $cardClass }} p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="{{ $toneBadge($presenter->statusTone($status)) }}">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                                {{ $presenter->statusLabel($status) }}
                            </span>
                            <span class="rounded-full bg-[#f4f1ec] px-2.5 py-1 text-xs font-semibold text-[#6b645e]">
                                {{ $presenter->subjectLabel($request->subject_type) }}
                            </span>
                            <span class="rounded-full bg-[#f4f1ec] px-2.5 py-1 text-xs font-semibold text-[#6b645e]">
                                {{ $presenter->requestTypeLabel($request->type) }}
                            </span>
                        </div>

                        <h3 class="text-base font-semibold text-[#17191c]">{{ $presenter->entityTitle($request) }}</h3>

                        @if($submittedAt !== null)
                            <p class="text-xs tabular-nums text-[#6b645e]">{{ __('Submitted :date', ['date' => $submittedAt]) }}</p>
                        @endif

                        @if(filled($summary))
                            <p class="break-words text-sm leading-6 text-[#3f3b37]">{{ $summary }}</p>
                        @endif

                        @if(filled($request->proposer_note))
                            <p class="text-sm leading-6 text-[#3f3b37]">{{ $request->proposer_note }}</p>
                        @endif

                        @if(filled($request->reviewer_note))
                            <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">
                                <span class="font-semibold text-[#17191c]">{{ __('Reviewer note') }}:</span>
                                {{ $request->reviewer_note }}
                            </p>
                        @endif

                        @if($presenter->rejectionReasonLabel($request->reason_code) !== null)
                            <p class="text-sm leading-6 text-rose-700">
                                <span class="font-semibold">{{ __('Reason') }}:</span>
                                {{ $presenter->rejectionReasonLabel($request->reason_code) }}
                            </p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-stretch">
                        <button type="button"
                            wire:click="inspectItem('request:{{ $request->id }}')"
                            wire:loading.attr="disabled" wire:target="inspectItem"
                            class="{{ $pillEmeraldOutline }}">
                            {{ __('View details') }}
                        </button>
                        @if($publicUrl !== null)
                            <a href="{{ $publicUrl }}" wire:navigate class="{{ $pillSecondary }}">
                                {{ __('View public record') }}
                            </a>
                        @endif
                        @if($status === 'pending')
                            <button type="button"
                                wire:click="cancelContributionRequest('{{ $request->id }}')"
                                wire:confirm="{{ __('Cancel this contribution request? This cannot be undone.') }}"
                                wire:loading.attr="disabled" wire:target="cancelContributionRequest"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50">
                                {{ __('Cancel') }}
                            </button>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="{{ $cardClass }} px-5 py-14 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-50 text-[#065f46]" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                    </svg>
                </div>
                <p class="mt-4 text-base font-semibold text-[#17191c]">{{ __('No directory requests yet.') }}</p>
                <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Add an institution, speaker or reference that is still missing.') }}</p>
                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    <a href="{{ route('contributions.submit-institution') }}" wire:navigate class="{{ $pillEmeraldOutline }}">{{ __('Institution') }}</a>
                    <a href="{{ route('contributions.submit-person') }}" wire:navigate class="{{ $pillEmeraldOutline }}">{{ __('Speaker') }}</a>
                    <a href="{{ route('contributions.submit-reference') }}" wire:navigate class="{{ $pillEmeraldOutline }}">{{ __('Reference') }}</a>
                </div>
            </div>
        @endforelse
    </div>

    @if($directorySubmissions->hasPages())
        <div class="mt-6">{{ $directorySubmissions->links() }}</div>
    @endif
</section>
