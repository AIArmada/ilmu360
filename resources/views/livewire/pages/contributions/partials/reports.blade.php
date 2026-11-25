@php
    $myReports = $this->myReports;
@endphp

<section aria-labelledby="contributions-reports-heading">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 id="contributions-reports-heading" class="font-heading text-2xl font-semibold text-[#17191c]">
                {{ __('Report Submissions') }}
            </h2>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-[#3f3b37]">
                {{ __('Problems you reported and how moderators resolved them.') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold tabular-nums text-[#065f46]">{{ $myReports->total() }}</span>
            <button type="button" wire:click="openRecordFinder('report', 'event')"
                wire:loading.attr="disabled" wire:target="openRecordFinder"
                @disabled($blocked) class="{{ $pillEmeraldOutline }}">
                {{ __('Find a record') }}
            </button>
        </div>
    </div>

    <div class="mt-5 space-y-3">
        @forelse($myReports as $report)
            @php
                $createdAt = $presenter->formatDateTime($report->createdAt);
            @endphp

            <article wire:key="report-{{ $report->id }}" class="{{ $cardClass }} p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="{{ $toneBadge($presenter->statusTone($report->status)) }}">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                                {{ $presenter->statusLabel($report->status) }}
                            </span>
                            <span class="rounded-full bg-[#f4f1ec] px-2.5 py-1 text-xs font-semibold text-[#6b645e]">{{ $report->entityLabel }}</span>
                        </div>

                        <h3 class="text-base font-semibold text-[#17191c]">{{ $report->entityTitle ?? $report->entityLabel }}</h3>

                        @if($createdAt !== null)
                            <p class="text-xs tabular-nums text-[#6b645e]">{{ __('Submitted :date', ['date' => $createdAt]) }}</p>
                        @endif

                        <p class="inline-flex w-fit rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">{{ $report->categoryLabel }}</p>

                        @if(filled($report->description))
                            <p class="text-sm leading-6 text-[#3f3b37]">{{ $report->description }}</p>
                        @endif

                        @if(filled($report->resolutionNote))
                            <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">
                                <span class="font-semibold text-[#17191c]">{{ __('Resolution') }}:</span>
                                {{ $report->resolutionNote }}
                            </p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-stretch">
                        <button type="button"
                            wire:click="inspectItem('report:{{ $report->id }}')"
                            wire:loading.attr="disabled" wire:target="inspectItem"
                            class="{{ $pillEmeraldOutline }}">
                            {{ __('View details') }}
                        </button>
                    </div>
                </div>
            </article>
        @empty
            <div class="{{ $cardClass }} px-5 py-14 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-rose-50 text-rose-700" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
                    </svg>
                </div>
                <p class="mt-4 text-base font-semibold text-[#17191c]">{{ __('No reports yet.') }}</p>
                <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Spot something wrong? Help us keep the platform accurate.') }}</p>
                <button type="button" wire:click="openRecordFinder('report', 'event')"
                    wire:loading.attr="disabled" wire:target="openRecordFinder"
                    @disabled($blocked) class="{{ $pillEmeraldOutline }} mt-5">
                    {{ __('Find a record') }}
                </button>
            </div>
        @endforelse
    </div>

    @if($myReports->hasPages())
        <div class="mt-6">{{ $myReports->links() }}</div>
    @endif
</section>
