@php
    $item = $this->inspectedItem;
    $isRequest = is_array($item) && $item['kind'] === 'request';
    $isUpdateRequest = $isRequest && $item['request_type'] === 'update';
    $isCreateRequest = $isRequest && $item['request_type'] !== 'update';
@endphp

<flux:modal
    wire:model.self="showDetailDrawer"
    variant="flyout"
    name="contributions-detail-drawer"
    class="w-full max-w-2xl bg-[#fafaf7]"
>
    @if($item !== null)
        <div class="space-y-5">
            {{-- Header --}}
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-[#f4f1ec] px-2.5 py-1 text-xs font-semibold text-[#6b645e]">{{ $item['family_label'] }}</span>
                        <span class="{{ $toneBadge($item['status_tone']) }}">
                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" />
                            </svg>
                            {{ $item['status_label'] }}
                        </span>
                    </div>
                    <h2 class="break-words font-heading text-2xl font-semibold text-[#17191c]">{{ $item['title'] }}</h2>
                </div>
                <button type="button" wire:click="closeDetail"
                    aria-label="{{ __('Close') }}"
                    class="flex size-11 shrink-0 items-center justify-center rounded-full border border-[#8c867e]/60 bg-white text-[#17191c] transition-colors hover:border-[#047857] hover:text-[#047857] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Timeline --}}
            <section class="rounded-2xl border border-[#e5ded8] bg-white p-4">
                <h3 class="text-sm font-semibold text-[#17191c]">{{ __('Timeline') }}</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @if(filled($item['submitted_at'] ?? null))
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-[#6b645e]">{{ __('Submitted') }}</dt>
                            <dd class="font-semibold tabular-nums text-[#17191c]">{{ $item['submitted_at'] }}</dd>
                        </div>
                    @endif
                    @if(filled($item['reviewed_at'] ?? null))
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-[#6b645e]">{{ __('Reviewed :date', ['date' => $item['reviewed_at']]) }}</dt>
                        </div>
                    @endif
                    @if(filled($item['approved_at'] ?? null))
                        <div class="flex items-center justify-between gap-3">
                            <dt class="font-semibold text-[#065f46]">{{ __('Approved :date', ['date' => $item['approved_at']]) }}</dt>
                        </div>
                    @endif
                    @if(filled($item['rejected_at'] ?? null))
                        <div class="flex items-center justify-between gap-3">
                            <dt class="font-semibold text-rose-700">{{ __('Rejected :date', ['date' => $item['rejected_at']]) }}</dt>
                        </div>
                    @endif
                    @if(filled($item['cancelled_at'] ?? null))
                        <div class="flex items-center justify-between gap-3">
                            <dt class="font-semibold text-[#6b645e]">{{ __('Cancelled :date', ['date' => $item['cancelled_at']]) }}</dt>
                        </div>
                    @endif
                </dl>
                @if(filled($item['reason_label'] ?? null))
                    <p class="mt-3 inline-flex w-fit rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">
                        {{ __('Reason') }}: {{ $item['reason_label'] }}
                    </p>
                @endif
            </section>

            {{-- What you contributed: update request --}}
            @if($isUpdateRequest)
                @if($item['changes'] !== [])
                    <section class="space-y-3">
                        <h3 class="text-sm font-semibold text-[#17191c]">{{ __('Proposed Changes') }}</h3>
                        <div class="space-y-3">
                            @foreach($item['changes'] as $change)
                                <div wire:key="receipt-change-{{ $item['id'] }}-{{ $loop->index }}" class="rounded-2xl border border-[#e5ded8] bg-white p-4">
                                    <p class="text-xs font-semibold text-[#6b645e]">{{ $change['field'] }}</p>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        <div class="rounded-xl bg-[#faf7f1] px-3 py-2">
                                            <p class="text-xs font-semibold text-[#6b645e]">{{ __('Original') }}</p>
                                            <p class="mt-1 break-words text-sm text-rose-700/90">{{ $change['original'] }}</p>
                                        </div>
                                        <div class="rounded-xl bg-emerald-50 px-3 py-2">
                                            <p class="text-xs font-semibold text-[#065f46]">{{ __('Proposed') }}</p>
                                            <p class="mt-1 break-words text-sm text-[#065f46]">{{ $change['proposed'] }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endif

            {{-- What you contributed: new submission --}}
            @if($isCreateRequest)
                <section class="rounded-2xl border border-[#e5ded8] bg-white p-4">
                    <h3 class="text-sm font-semibold text-[#17191c]">{{ __('Submitted Information') }}</h3>
                    @if($item['submitted_fields'] !== [])
                        <dl class="mt-3 space-y-3">
                            @foreach($item['submitted_fields'] as $field)
                                <div wire:key="receipt-field-{{ $item['id'] }}-{{ $loop->index }}" class="space-y-0.5">
                                    <dt class="text-xs font-semibold text-[#6b645e]">{{ $field['field'] }}</dt>
                                    <dd class="break-words text-sm text-[#17191c]">{{ $field['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @else
                        <p class="mt-3 text-sm leading-6 text-[#3f3b37]">{{ __('This contribution has no recorded changes.') }}</p>
                    @endif
                </section>
            @endif

            {{-- Uploaded media --}}
            @if($isRequest && $item['media'] !== [])
                <section class="space-y-3">
                    <h3 class="text-sm font-semibold text-[#17191c]">{{ __('Uploaded Media') }}</h3>
                    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach($item['media'] as $media)
                            <li wire:key="receipt-media-{{ $media['id'] }}" class="min-w-0 space-y-1.5">
                                @if($media['is_image'])
                                    <img src="{{ $media['url'] }}" alt="{{ $media['name'] }}"
                                        class="h-24 w-24 rounded-xl border border-[#e5ded8] object-cover" loading="lazy" />
                                @else
                                    <span class="flex h-24 w-24 items-center justify-center rounded-xl border border-[#e5ded8] bg-[#faf7f1] text-[#6b645e]" aria-hidden="true">
                                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                        </svg>
                                    </span>
                                @endif
                                <p class="text-xs font-semibold text-[#17191c]">{{ $media['field_label'] }}</p>
                                <p class="truncate text-xs text-[#6b645e]">{{ $media['name'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($isUpdateRequest && $item['changes'] === [] && $item['media'] === [])
                <p class="rounded-2xl border border-dashed border-[#e5ded8] px-4 py-4 text-sm leading-6 text-[#3f3b37]">
                    {{ __('This contribution has no recorded changes.') }}
                </p>
            @endif

            {{-- What you contributed: event submission --}}
            @if($item['kind'] === 'event')
                <section class="space-y-3 rounded-2xl border border-[#e5ded8] bg-white p-4">
                    @if(filled($item['parent_title'] ?? null))
                        <p class="text-sm font-medium text-[#3f3b37]">{{ __('Majlis: :title', ['title' => $item['parent_title']]) }}</p>
                    @endif
                    @if(filled($item['date_label'] ?? null))
                        <p class="text-sm font-semibold tabular-nums text-[#17191c]">
                            {{ $item['date_label'] }}@if(filled($item['time_label'] ?? null)) · {{ $item['time_label'] }}@endif
                        </p>
                    @endif
                    @if(($item['details'] ?? []) !== [])
                        <div class="flex flex-wrap gap-2 pt-1">
                            @foreach($item['details'] as $detail)
                                <span wire:key="receipt-detail-{{ $item['id'] }}-{{ $loop->index }}" class="rounded-full bg-[#faf7f1] px-2.5 py-1 text-xs font-medium text-[#3f3b37]">{{ $detail }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if(filled($item['notes'] ?? null))
                        <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">{{ $item['notes'] }}</p>
                    @endif
                </section>
            @endif

            {{-- What you contributed: report --}}
            @if($item['kind'] === 'report')
                <section class="space-y-3 rounded-2xl border border-[#e5ded8] bg-white p-4">
                    <p class="inline-flex w-fit rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">{{ $item['category_label'] }}</p>
                    @if(filled($item['description'] ?? null))
                        <p class="text-sm leading-6 text-[#3f3b37]">{{ $item['description'] }}</p>
                    @endif
                    @if(filled($item['resolution_note'] ?? null))
                        <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">
                            <span class="font-semibold text-[#17191c]">{{ __('Resolution') }}:</span>
                            {{ $item['resolution_note'] }}
                        </p>
                    @endif
                </section>
            @endif

            {{-- What you contributed: membership claim --}}
            @if($item['kind'] === 'claim')
                <section class="space-y-3 rounded-2xl border border-[#e5ded8] bg-white p-4">
                    <dl class="space-y-2 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-[#6b645e]">{{ __('Role') }}</dt>
                            <dd class="font-semibold text-[#17191c]">{{ $item['role_label'] }}</dd>
                        </div>
                        @if(filled($item['granted_role'] ?? null))
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-[#6b645e]">{{ __('Granted role') }}</dt>
                                <dd class="font-semibold text-[#065f46]">{{ $item['granted_role'] }}</dd>
                            </div>
                        @endif
                    </dl>
                    @if(filled($item['justification'] ?? null))
                        <p class="text-sm leading-6 text-[#3f3b37]">{{ $item['justification'] }}</p>
                    @endif
                    @if(filled($item['notes'] ?? null))
                        <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">
                            <span class="font-semibold text-[#17191c]">{{ __('Catatan') }}:</span>
                            {{ $item['notes'] }}
                        </p>
                    @endif
                    @if(($item['evidence'] ?? []) !== [])
                        <div>
                            <p class="text-xs font-semibold text-[#6b645e]">
                                {{ trans_choice(':count evidence file|:count evidence files', count($item['evidence']), ['count' => count($item['evidence'])]) }}
                            </p>
                            <ul class="mt-2 space-y-1">
                                @foreach($item['evidence'] as $evidence)
                                    <li wire:key="receipt-evidence-{{ $evidence['id'] }}" class="truncate text-sm text-[#17191c]">{{ $evidence['name'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endif

            {{-- What you contributed: app feedback --}}
            @if($item['kind'] === 'feedback')
                <section class="space-y-3 rounded-2xl border border-[#e5ded8] bg-white p-4">
                    <p class="text-sm leading-6 text-[#3f3b37]">{{ $item['description'] }}</p>
                    @if(filled($item['page_url'] ?? null))
                        <p class="truncate text-xs text-[#6b645e]">{{ __('Reported from :page', ['page' => $item['page_url']]) }}</p>
                    @endif
                    @if(($item['replies_count'] ?? 0) > 0)
                        <p class="inline-flex w-fit rounded-full bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-800">
                            {{ trans_choice(':count reply|:count replies', $item['replies_count'], ['count' => $item['replies_count']]) }}
                        </p>
                    @endif
                    @if(filled($item['resolution_note'] ?? null))
                        <p class="rounded-2xl bg-[#faf7f1] px-4 py-3 text-sm leading-6 text-[#3f3b37]">
                            <span class="font-semibold text-[#17191c]">{{ __('Resolution') }}:</span>
                            {{ $item['resolution_note'] }}
                        </p>
                    @endif
                </section>
            @endif

            {{-- Notes --}}
            @if(filled($item['proposer_note'] ?? null) || filled($item['reviewer_note'] ?? null))
                <div class="space-y-3">
                    @if(filled($item['proposer_note'] ?? null))
                        <div class="rounded-2xl border border-[#e5ded8] bg-white p-4">
                            <p class="text-xs font-semibold text-[#6b645e]">{{ __('Your note') }}</p>
                            <p class="mt-1 text-sm leading-6 text-[#3f3b37]">{{ $item['proposer_note'] }}</p>
                        </div>
                    @endif
                    @if(filled($item['reviewer_note'] ?? null))
                        <div class="rounded-2xl border border-[#e5ded8] bg-white p-4">
                            <p class="text-xs font-semibold text-[#6b645e]">{{ __('Reviewer note') }}</p>
                            <p class="mt-1 text-sm leading-6 text-[#3f3b37]">{{ $item['reviewer_note'] }}</p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Footer actions --}}
            <div class="flex flex-wrap items-center gap-2 border-t border-[#e5ded8] pt-4">
                @if($item['can_cancel'] ?? false)
                    <button type="button"
                        wire:click="{{ $item['kind'] === 'claim' ? 'cancelMembershipApplication' : 'cancelContributionRequest' }}('{{ $item['id'] }}')"
                        wire:confirm="{{ $item['kind'] === 'claim' ? __('Cancel this membership claim? This cannot be undone.') : __('Cancel this contribution request? This cannot be undone.') }}"
                        wire:loading.attr="disabled"
                        wire:target="cancelMembershipApplication,cancelContributionRequest"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50">
                        {{ $item['kind'] === 'claim' ? __('Cancel claim') : __('Cancel request') }}
                    </button>
                @endif

                @if($item['kind'] === 'feedback' && filled($item['thread_url'] ?? null))
                    <a href="{{ $item['thread_url'] }}" wire:navigate class="{{ $pillEmeraldOutline }}">
                        {{ __('Open full thread') }}
                    </a>
                @endif

                @if(filled($item['public_url'] ?? null))
                    <a href="{{ $item['public_url'] }}" wire:navigate class="{{ $pillSecondary }}">
                        {{ __('View public record') }}
                    </a>
                @endif

                <button type="button" wire:click="closeDetail"
                    class="inline-flex min-h-11 items-center rounded-full px-3 text-sm font-semibold text-[#6b645e] underline-offset-4 transition-colors hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#047857] active:scale-[0.98]">
                    {{ __('Close') }}
                </button>
            </div>
        </div>
    @endif
</flux:modal>
