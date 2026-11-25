@php
    $recentActivity = $this->recentActivity;
@endphp

<section aria-labelledby="contributions-launcher-heading">
    <div class="flex items-end justify-between gap-3">
        <div>
            <h2 id="contributions-launcher-heading" class="font-heading text-2xl font-semibold text-[#17191c]">
                {{ __('Mula Menyumbang') }}
            </h2>
            <p class="mt-1 text-sm leading-6 text-[#3f3b37]">{{ __('Choose what you want to contribute. Every submission is reviewed by a moderator.') }}</p>
        </div>
    </div>

    <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {{-- Events --}}
        <article class="{{ $cardClass }} flex flex-col p-6">
            <span class="flex size-11 items-center justify-center rounded-2xl bg-emerald-50 text-[#065f46]" aria-hidden="true">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 9v7.5" />
                </svg>
            </span>
            <h3 class="mt-4 font-heading text-xl font-semibold text-[#17191c]">{{ __('Majlis Ilmu (Events)') }}</h3>
            <p class="mt-2 grow text-sm leading-6 text-[#3f3b37]">{{ __('Submit a majlis date with its venue, speakers and references. It appears publicly once approved.') }}</p>
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <a href="{{ route('submit-event.create') }}" wire:navigate
                    data-signal-event="submission.event_start_clicked"
                    data-signal-category="submission"
                    data-signal-component="contributions_hub"
                    data-signal-control="launcher_add_event"
                    class="{{ $pillEmeraldOutline }}">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" d="M12 6v12M6 12h12" />
                    </svg>
                    {{ __('Add Event') }}
                </a>
                @if($viewer['has_any_workspace_access'])
                    <a href="{{ route('dashboard.events.create-advanced') }}" wire:navigate
                        data-signal-event="contribution.hub_launcher_clicked"
                        data-signal-category="contribution"
                        data-signal-component="contributions_hub"
                        data-signal-props='@json(['action' => 'create', 'subject_type' => 'advanced_event'])'
                        class="inline-flex min-h-11 items-center text-sm font-semibold text-[#047857] underline-offset-4 transition-colors hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857]">
                        {{ __('Series / Multi-session') }}
                    </a>
                @endif
            </div>
        </article>

        {{-- Directory records --}}
        <article class="{{ $cardClass }} flex flex-col p-6">
            <span class="flex size-11 items-center justify-center rounded-2xl bg-[#f4f1ec] text-[#6b645e]" aria-hidden="true">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                </svg>
            </span>
            <h3 class="mt-4 font-heading text-xl font-semibold text-[#17191c]">{{ __('New Directory Record') }}</h3>
            <p class="mt-2 grow text-sm leading-6 text-[#3f3b37]">{{ __('Add an institution, speaker or reference that is still missing from the directory.') }}</p>
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <a href="{{ route('contributions.submit-institution') }}" wire:navigate
                    data-signal-event="contribution.hub_launcher_clicked"
                    data-signal-category="contribution"
                    data-signal-component="contributions_hub"
                    data-signal-props='@json(['action' => 'create', 'subject_type' => 'institution'])'
                    class="{{ $pillSecondary }}">{{ __('Institution') }}</a>
                <a href="{{ route('contributions.submit-person') }}" wire:navigate
                    data-signal-event="contribution.hub_launcher_clicked"
                    data-signal-category="contribution"
                    data-signal-component="contributions_hub"
                    data-signal-props='@json(['action' => 'create', 'subject_type' => 'person'])'
                    class="{{ $pillSecondary }}">{{ __('Speaker') }}</a>
                <a href="{{ route('contributions.submit-reference') }}" wire:navigate
                    data-signal-event="contribution.hub_launcher_clicked"
                    data-signal-category="contribution"
                    data-signal-component="contributions_hub"
                    data-signal-props='@json(['action' => 'create', 'subject_type' => 'reference'])'
                    class="{{ $pillSecondary }}">{{ __('Reference') }}</a>
            </div>
        </article>

        {{-- Update suggestions --}}
        <article class="{{ $cardClass }} flex flex-col p-6">
            <span class="flex size-11 items-center justify-center rounded-2xl bg-amber-50 text-amber-800" aria-hidden="true">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                </svg>
            </span>
            <h3 class="mt-4 font-heading text-xl font-semibold text-[#17191c]">{{ __('Suggest an Update') }}</h3>
            <p class="mt-2 grow text-sm leading-6 text-[#3f3b37]">{{ __('Find any event, institution, speaker or reference and suggest a correction.') }}</p>
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="openRecordFinder('update', 'event')"
                    wire:loading.attr="disabled" wire:target="openRecordFinder"
                    @disabled($blocked) class="{{ $pillEmeraldOutline }}">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                    </svg>
                    {{ __('Find a record') }}
                </button>
            </div>
        </article>

        {{-- Reports --}}
        <article class="{{ $cardClass }} flex flex-col p-6">
            <span class="flex size-11 items-center justify-center rounded-2xl bg-rose-50 text-rose-700" aria-hidden="true">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
                </svg>
            </span>
            <h3 class="mt-4 font-heading text-xl font-semibold text-[#17191c]">{{ __('Report Inaccurate Information') }}</h3>
            <p class="mt-2 grow text-sm leading-6 text-[#3f3b37]">{{ __('Pick a record and tell moderators what is wrong. Reports are reviewed, never auto-hidden.') }}</p>
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="openRecordFinder('report', 'event')"
                    wire:loading.attr="disabled" wire:target="openRecordFinder"
                    @disabled($blocked) class="{{ $pillEmeraldOutline }}">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                    </svg>
                    {{ __('Find a record') }}
                </button>
            </div>
        </article>

        {{-- Membership claims --}}
        <article class="{{ $cardClass }} flex flex-col p-6">
            <span class="flex size-11 items-center justify-center rounded-2xl bg-sky-50 text-sky-800" aria-hidden="true">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
            </span>
            <h3 class="mt-4 font-heading text-xl font-semibold text-[#17191c]">{{ __('Claim Management') }}</h3>
            <p class="mt-2 grow text-sm leading-6 text-[#3f3b37]">{{ __('Claim an institution or speaker profile you represent. Bring 1–8 evidence files; a moderator verifies the claim.') }}</p>
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="selectTab('membership')" wire:loading.attr="disabled" wire:target="selectTab"
                    class="{{ $pillEmeraldOutline }}">
                    {{ __('Find your record') }}
                </button>
                <button type="button" wire:click="openRecordFinder('claim', 'institution')"
                    wire:loading.attr="disabled" wire:target="openRecordFinder"
                    @disabled($blocked)
                    class="inline-flex min-h-11 items-center text-sm font-semibold text-[#047857] underline-offset-4 transition-colors hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] disabled:cursor-not-allowed disabled:opacity-50">
                    {{ __('Search records') }}
                </button>
            </div>
        </article>

        {{-- App feedback --}}
        <article class="{{ $cardClass }} flex flex-col p-6">
            <span class="flex size-11 items-center justify-center rounded-2xl bg-emerald-50 text-[#065f46]" aria-hidden="true">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                </svg>
            </span>
            <h3 class="mt-4 font-heading text-xl font-semibold text-[#17191c]">{{ __('App Feedback') }}</h3>
            <p class="mt-2 grow text-sm leading-6 text-[#3f3b37]">{{ __('Report a bug, request a feature or flag a performance issue. Track every reply in one thread.') }}</p>
            <div class="mt-5 flex flex-wrap items-center gap-2">
                @if($blocked)
                    <button type="button" disabled class="{{ $pillEmeraldOutline }}">
                        {{ __('Feedback paused') }}
                    </button>
                @else
                    <a href="{{ route('app-feedback.index', ['dari' => '/sumbangan']) }}" wire:navigate
                        data-signal-event="contribution.hub_launcher_clicked"
                        data-signal-category="contribution"
                        data-signal-component="contributions_hub"
                        data-signal-props='@json(['action' => 'create', 'subject_type' => 'app_feedback'])'
                        class="{{ $pillEmeraldOutline }}">
                        {{ __('Send feedback') }}
                    </a>
                @endif
                <button type="button" wire:click="selectTab('feedback')" wire:loading.attr="disabled" wire:target="selectTab"
                    class="inline-flex min-h-11 items-center text-sm font-semibold text-[#047857] underline-offset-4 transition-colors hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857]">
                    {{ __('Track my threads') }}
                </button>
            </div>
        </article>
    </div>
</section>

<section class="mt-8" aria-labelledby="contributions-activity-heading">
    <div>
        <h2 id="contributions-activity-heading" class="font-heading text-2xl font-semibold text-[#17191c]">
            {{ __('Recent Activity') }}
        </h2>
        <p class="mt-1 text-sm leading-6 text-[#3f3b37]">{{ __('Your latest contributions across all types, newest first.') }}</p>
    </div>

    <ol class="mt-5 space-y-3">
        @forelse($recentActivity as $activity)
            <li wire:key="activity-{{ $activity['key'] }}">
                <button type="button"
                    wire:click="inspectItem('{{ $activity['inspect_key'] }}')"
                    wire:loading.attr="disabled" wire:target="inspectItem"
                    class="{{ $cardClass }} block w-full p-5 text-left transition-colors hover:border-[#047857] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-[#f4f1ec] px-2.5 py-1 text-xs font-semibold text-[#6b645e]">{{ $activity['family'] }}</span>
                                <span class="{{ $toneBadge($activity['status_color']) }}">
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                        <circle cx="12" cy="12" r="9" />
                                    </svg>
                                    {{ $activity['status_label'] }}
                                </span>
                            </div>
                            <p class="truncate text-base font-semibold text-[#17191c]">{{ $activity['title'] }}</p>
                            @if(filled($activity['subtitle']))
                                <p class="text-sm text-[#3f3b37]">{{ $activity['subtitle'] }}</p>
                            @endif
                            @if(filled($activity['summary']))
                                <p class="break-words text-sm leading-6 text-[#3f3b37]">{{ $activity['summary'] }}</p>
                            @endif
                            @if(filled($activity['proposer_note']))
                                <p class="break-words text-sm leading-6 text-[#6b645e]">{{ $activity['proposer_note'] }}</p>
                            @endif
                            @if(filled($activity['note']))
                                <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">{{ $activity['note'] }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end">
                            @if($presenter->formatDate($activity['occurred_at']) !== null)
                                <time class="text-xs font-semibold tabular-nums text-[#6b645e]" datetime="{{ $activity['occurred_at'] }}">
                                    {{ $presenter->formatDate($activity['occurred_at']) }}
                                </time>
                            @endif
                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-[#047857]">
                                {{ __('View details') }}
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                            </span>
                        </div>
                    </div>
                </button>
            </li>
        @empty
            <li class="{{ $cardClass }} px-5 py-14 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-50 text-[#065f46]" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                    </svg>
                </div>
                <p class="mt-4 text-base font-semibold text-[#17191c]">{{ __('Nothing contributed yet.') }}</p>
                <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Start with a majlis date or a missing directory record.') }}</p>
            </li>
        @endforelse
    </ol>
</section>
