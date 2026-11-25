@php
    $claims = $this->myMembershipClaims;
@endphp

<section aria-labelledby="contributions-membership-heading">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 id="contributions-membership-heading" class="font-heading text-2xl font-semibold text-[#17191c]">
                {{ __('Membership Claims') }}
            </h2>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-[#3f3b37]">
                {{ __('Claim management access to an institution or speaker you represent, then track every claim here.') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold tabular-nums text-[#065f46]">{{ $claims->count() }}</span>
            <a href="{{ route('membership-applications.index') }}" wire:navigate class="{{ $pillSecondary }}">
                {{ __('View all claims') }}
            </a>
        </div>
    </div>

    {{-- Inline claim starter --}}
    @if($blocked)
        <div class="{{ $cardClass }} mt-5 p-5 sm:p-6" role="status">
            <p class="text-sm font-semibold text-amber-900">{{ __('Directory feedback is paused for your account') }}</p>
            <p class="mt-1 text-sm leading-6 text-[#3f3b37]">{{ $viewer['feedback_ban_message'] }}</p>
            <a href="{{ route('contact') }}" wire:navigate class="{{ $pillSecondary }} mt-4">
                {{ __('Contact Us') }}
            </a>
        </div>
    @else
        @php
            $claimSearchResults = $this->claimSearchResults;
        @endphp

        <div class="{{ $cardClass }} mt-5 p-5 sm:p-6">
        <p class="text-sm font-semibold text-[#065f46]">{{ __('Start a new claim') }}</p>
        <p class="mt-1 text-sm leading-6 text-[#3f3b37]">
            {{ __('Search for the institution or speaker you represent. Claims need 1–8 evidence files and a moderator reviews each one.') }}
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$set('claimSubjectType', 'institution')"
                wire:loading.attr="disabled" wire:target="claimSubjectType"
                aria-pressed="{{ $this->claimSubjectType === 'institution' ? 'true' : 'false' }}"
                @class([
                    'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-4 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]',
                    'border-[#047857] bg-white font-bold text-[#065f46]' => $this->claimSubjectType === 'institution',
                    'border-[#8c867e]/50 bg-white/70 font-semibold text-[#3f3b37] hover:border-[#047857] hover:text-[#047857]' => $this->claimSubjectType !== 'institution',
                ])>
                @if($this->claimSubjectType === 'institution')
                    <svg class="size-3.5 text-[#047857]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                @endif
                {{ __('Institution') }}
            </button>
            <button type="button" wire:click="$set('claimSubjectType', 'person')"
                wire:loading.attr="disabled" wire:target="claimSubjectType"
                aria-pressed="{{ $this->claimSubjectType === 'person' ? 'true' : 'false' }}"
                @class([
                    'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-4 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]',
                    'border-[#047857] bg-white font-bold text-[#065f46]' => $this->claimSubjectType === 'person',
                    'border-[#8c867e]/50 bg-white/70 font-semibold text-[#3f3b37] hover:border-[#047857] hover:text-[#047857]' => $this->claimSubjectType !== 'person',
                ])>
                @if($this->claimSubjectType === 'person')
                    <svg class="size-3.5 text-[#047857]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                @endif
                {{ __('Speaker') }}
            </button>
        </div>

        <label class="relative mt-4 block">
            <span class="sr-only">{{ __('Search by name') }}</span>
            <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#8c867e]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
            </svg>
            <input type="search" wire:model.live.debounce.300ms="claimSearch"
                placeholder="{{ __('Search by name') }}"
                class="{{ $inputClass }} pl-10" />
        </label>

        @if($this->selectedClaimIdentifier !== null)
            <div class="mt-4 flex flex-col gap-3 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-[#065f46]" aria-hidden="true">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-[#065f46]">{{ __('Selected record') }}</p>
                        <p class="text-sm text-[#3f3b37]">{{ $this->selectedClaimLabel }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="clearClaimSelection" wire:loading.attr="disabled" wire:target="clearClaimSelection"
                        class="inline-flex min-h-11 items-center rounded-full px-3 text-sm font-semibold text-[#6b645e] underline-offset-4 transition-colors hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857]">
                        {{ __('Change') }}
                    </button>
                    <button type="button" wire:click="startMembershipClaim" wire:loading.attr="disabled" wire:target="startMembershipClaim"
                        class="{{ $pillEmeraldOutline }}">
                        {{ __('Continue to claim form') }}
                    </button>
                </div>
            </div>
        @endif

        @error('selectedClaimIdentifier')
            <p class="mt-3 text-sm font-semibold text-rose-700" role="alert">{{ $message }}</p>
        @enderror

        <ul class="mt-4 space-y-2">
            @forelse($claimSearchResults as $result)
                <li wire:key="claim-option-{{ $result['identifier'] }}">
                    <button type="button"
                        wire:click="selectClaimRecord('{{ $result['identifier'] }}', {{ \Illuminate\Support\Js::from($result['label']) }})"
                        wire:loading.attr="disabled" wire:target="selectClaimRecord"
                        @class([
                            'flex min-h-11 w-full items-center justify-between gap-3 rounded-2xl border bg-white px-4 py-3 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.99]',
                            'border-[#047857]' => $this->selectedClaimIdentifier === $result['identifier'],
                            'border-[#e5ded8] hover:border-[#047857]' => $this->selectedClaimIdentifier !== $result['identifier'],
                        ])>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-[#17191c]">{{ $result['label'] }}</span>
                            @if(filled($result['subtitle']))
                                <span class="mt-0.5 block truncate text-xs text-[#6b645e]">{{ $result['subtitle'] }}</span>
                            @endif
                        </span>
                        <svg class="size-4 shrink-0 text-[#8c867e]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                </li>
            @empty
                <li class="rounded-2xl border border-dashed border-[#e5ded8] px-4 py-6 text-center">
                    <p class="text-sm font-semibold text-[#17191c]">{{ __('No matching records found.') }}</p>
                    <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Try a different name, or add the record to the directory first.') }}</p>
                </li>
            @endforelse
        </ul>
    </div>
    @endif

    {{-- Existing claims --}}
    <div class="mt-6 space-y-3">
        @forelse($claims as $claim)
            @php
                $claimStatus = $claim->application->status;
                $submittedAt = $presenter->formatDate($claim->application->submittedAt);
            @endphp

            <article wire:key="claim-{{ $claim->application->id }}" class="{{ $cardClass }} p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="{{ $toneBadge($presenter->statusTone($claimStatus)) }}">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                                {{ $presenter->statusLabel($claimStatus) }}
                            </span>
                            <span class="rounded-full bg-[#f4f1ec] px-2.5 py-1 text-xs font-semibold text-[#6b645e]">{{ $claim->subjectLabel }}</span>
                            @if($claim->grantedRole !== null)
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-[#065f46]">
                                    {{ __('Role: :role', ['role' => __($claim->roleLabel)]) }}
                                </span>
                            @endif
                        </div>

                        <h3 class="text-base font-semibold text-[#17191c]">{{ $claim->subjectTitle }}</h3>

                        @if($submittedAt !== null)
                            <p class="text-xs tabular-nums text-[#6b645e]">{{ __('Submitted :date', ['date' => $submittedAt]) }}</p>
                        @endif

                        @if(filled($claim->justification))
                            <p class="text-sm leading-6 text-[#3f3b37]">{{ $claim->justification }}</p>
                        @endif

                        @if(filled($claim->notes))
                            <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">
                                <span class="font-semibold text-[#17191c]">{{ __('Catatan') }}:</span>
                                {{ $claim->notes }}
                            </p>
                        @endif

                        @if(filled($claim->reviewerNote))
                            <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">
                                <span class="font-semibold text-[#17191c]">{{ __('Reviewer note') }}:</span>
                                {{ $claim->reviewerNote }}
                            </p>
                        @endif

                        @if($claim->evidence !== [])
                            <p class="text-xs text-[#6b645e]">{{ __(':count evidence files attached', ['count' => count($claim->evidence)]) }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-stretch">
                        <button type="button"
                            wire:click="inspectItem('claim:{{ $claim->application->id }}')"
                            wire:loading.attr="disabled" wire:target="inspectItem"
                            class="{{ $pillEmeraldOutline }}">
                            {{ __('View details') }}
                        </button>
                        @if($claim->subjectPublicUrl !== null)
                            <a href="{{ $claim->subjectPublicUrl }}" wire:navigate class="{{ $pillSecondary }}">
                                {{ __('View public record') }}
                            </a>
                        @endif
                        @if($claimStatus === 'pending')
                            <button type="button"
                                wire:click="cancelMembershipApplication('{{ $claim->application->id }}')"
                                wire:confirm="{{ __('Cancel this membership claim? This cannot be undone.') }}"
                                wire:loading.attr="disabled" wire:target="cancelMembershipApplication"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50">
                                {{ __('Cancel claim') }}
                            </button>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="{{ $cardClass }} px-5 py-14 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-sky-50 text-sky-800" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                </div>
                <p class="mt-4 text-base font-semibold text-[#17191c]">{{ __('No membership claims yet.') }}</p>
                <p class="mt-1 text-sm text-[#3f3b37]">{{ __('Search above for the institution or speaker you represent to begin.') }}</p>
            </div>
        @endforelse
    </div>
</section>
