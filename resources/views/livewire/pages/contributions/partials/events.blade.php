@php
    $submittedEvents = $this->submittedEvents;
@endphp

<section aria-labelledby="contributions-events-heading">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 id="contributions-events-heading" class="font-heading text-2xl font-semibold text-[#17191c]">
                {{ __('Event Submissions') }}
            </h2>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-[#3f3b37]">
                {{ __('Every majlis date you submit appears here with its review status, schedule and linked records.') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold tabular-nums text-[#065f46]">{{ $submittedEvents->total() }}</span>
            <a href="{{ route('submit-event.create') }}" wire:navigate
                data-signal-event="submission.event_start_clicked"
                data-signal-category="submission"
                data-signal-component="contributions_hub"
                data-signal-control="events_tab_add_event"
                class="{{ $pillEmeraldOutline }}">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" d="M12 6v12M6 12h12" />
                </svg>
                {{ __('Add Event') }}
            </a>
        </div>
    </div>

    @if($viewer['manages_institution'])
        <p class="mt-4 rounded-2xl border border-[#e5ded8] bg-white/85 px-4 py-3 text-sm leading-6 text-[#3f3b37]">
            {{ __('Events submitted for institutions you manage appear in your institution dashboard.') }}
            <a href="{{ route('dashboard.institutions') }}" wire:navigate
                class="font-semibold text-[#047857] underline-offset-4 transition-colors hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857]">
                {{ __('Manage Institution') }}
            </a>
        </p>
    @endif

    <div class="mt-5 space-y-3">
        @forelse($submittedEvents as $submission)
            @php
                $presentation = $submission->presentation;
                $statusValue = (string) ($presentation['status_value'] ?? '');
                $statusTone = $presenter->statusTone($statusValue);
                $statusLabel = (string) ($presentation['status_label'] ?? $presenter->statusLabel($statusValue));
                $url = $presentation['url'] ?? null;
                $submittedAt = $presenter->formatDateTime($submission->createdAt);
            @endphp

            <article wire:key="event-submission-{{ $submission->id }}" class="{{ $cardClass }} p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-[#f4f1ec] px-2.5 py-1 text-xs font-semibold text-[#6b645e]">
                                {{ $submission->occurrenceId !== null ? __('Occurrence') : __('Event') }}
                            </span>
                            @if($statusLabel !== '')
                                <span class="{{ $toneBadge($statusTone) }}">
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                        <circle cx="12" cy="12" r="9" />
                                    </svg>
                                    {{ $statusLabel }}
                                </span>
                            @endif
                        </div>

                        <h3 class="text-base font-semibold text-[#17191c]">{{ $presentation['title'] ?? $submission->eventTitle ?? __('Event') }}</h3>

                        @if(filled($presentation['parent_title'] ?? null))
                            <p class="text-sm font-medium text-[#3f3b37]">{{ __('Majlis: :title', ['title' => $presentation['parent_title']]) }}</p>
                        @endif

                        @if(filled($presentation['date_label'] ?? null))
                            <p class="text-sm font-semibold tabular-nums text-[#17191c]">
                                {{ $presentation['date_label'] }}@if(filled($presentation['time_label'] ?? null)) · {{ $presentation['time_label'] }}@endif
                            </p>
                        @endif

                        @if($submittedAt !== null)
                            <p class="text-xs tabular-nums text-[#6b645e]">{{ __('Submitted :date', ['date' => $submittedAt]) }}</p>
                        @endif

                        @if($submission->details !== [])
                            <div class="flex flex-wrap gap-2 pt-1">
                                @foreach($submission->details as $detail)
                                    <span wire:key="event-detail-{{ $submission->id }}-{{ $loop->index }}" class="rounded-full bg-[#faf7f1] px-2.5 py-1 text-xs font-medium text-[#3f3b37]">{{ $detail }}</span>
                                @endforeach
                            </div>
                        @endif

                        @if(filled($submission->notes))
                            <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">{{ $submission->notes }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-stretch">
                        <button type="button"
                            wire:click="inspectItem('event:{{ $submission->id }}')"
                            wire:loading.attr="disabled" wire:target="inspectItem"
                            class="{{ $pillEmeraldOutline }}">
                            {{ __('View details') }}
                        </button>
                        @if($url !== null)
                            <a href="{{ $url }}" wire:navigate class="{{ $pillSecondary }}">
                                {{ __('View public record') }}
                            </a>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="{{ $cardClass }} px-5 py-14 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-50 text-[#065f46]" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 9v7.5" />
                    </svg>
                </div>
                <p class="mt-4 text-base font-semibold text-[#17191c]">{{ __('No event submissions yet.') }}</p>
                <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Submit your first majlis date to get started.') }}</p>
                <a href="{{ route('submit-event.create') }}" wire:navigate class="{{ $pillEmeraldOutline }} mt-5">
                    {{ __('Add Event') }}
                </a>
            </div>
        @endforelse
    </div>

    @if($submittedEvents->hasPages())
        <div class="mt-6">{{ $submittedEvents->links() }}</div>
    @endif
</section>
