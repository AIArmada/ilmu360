@php
    $updateRequests = $this->updateRequests;
@endphp

<section aria-labelledby="contributions-updates-heading">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 id="contributions-updates-heading" class="font-heading text-2xl font-semibold text-[#17191c]">
                {{ __('Update Suggestions') }}
            </h2>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-[#3f3b37]">
                {{ __('Corrections you proposed for existing records, with the exact field changes sent to moderators.') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold tabular-nums text-[#065f46]">{{ $updateRequests->total() }}</span>
            <button type="button" wire:click="openRecordFinder('update', 'event')"
                wire:loading.attr="disabled" wire:target="openRecordFinder"
                @disabled($blocked) class="{{ $pillEmeraldOutline }}">
                {{ __('Find a record') }}
            </button>
        </div>
    </div>

    <div class="mt-5 space-y-3">
        @forelse($updateRequests as $request)
            @php
                $status = $request->status->value;
                $submittedAt = $presenter->formatDateTime($request->created_at);
                $changes = $presenter->changedFieldSummaries($request);
                $publicUrl = $presenter->requestPublicUrl($request);
                $summary = $presenter->requestSummaryLine($request);
            @endphp

            <article wire:key="update-request-{{ $request->id }}" class="{{ $cardClass }} p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1 space-y-2">
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

                        @if($changes !== [])
                            <details class="group rounded-2xl border border-[#e5ded8] bg-[#fdfbf7]">
                                <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-2 rounded-2xl px-4 text-sm font-semibold text-[#17191c] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857]">
                                    {{ __(':count changed fields', ['count' => count($changes)]) }}
                                    <svg class="size-4 text-[#6b645e] transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </summary>
                                <dl class="space-y-3 border-t border-[#e5ded8] px-4 py-4">
                                    @foreach($changes as $change)
                                        <div wire:key="update-change-{{ $request->id }}-{{ $loop->index }}" class="space-y-1">
                                            <dt class="text-xs font-semibold text-[#6b645e]">{{ $change['field'] }}</dt>
                                            <dd class="flex flex-col gap-1 text-sm sm:flex-row sm:items-center sm:gap-2">
                                                <span class="rounded-lg bg-rose-50 px-2.5 py-1 text-rose-700 line-through decoration-rose-400/70">{{ $change['original'] }}</span>
                                                <svg class="hidden size-4 shrink-0 text-[#8c867e] sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                                </svg>
                                                <span class="rounded-lg bg-emerald-50 px-2.5 py-1 text-[#065f46]">{{ $change['proposed'] }}</span>
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </details>
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
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-amber-50 text-amber-800" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                    </svg>
                </div>
                <p class="mt-4 text-base font-semibold text-[#17191c]">{{ __('No update suggestions yet.') }}</p>
                <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Found something that is out of date? Suggest a correction.') }}</p>
                <button type="button" wire:click="openRecordFinder('update', 'event')"
                    wire:loading.attr="disabled" wire:target="openRecordFinder"
                    @disabled($blocked) class="{{ $pillEmeraldOutline }} mt-5">
                    {{ __('Find a record') }}
                </button>
            </div>
        @endforelse
    </div>

    @if($updateRequests->hasPages())
        <div class="mt-6">{{ $updateRequests->links() }}</div>
    @endif
</section>
