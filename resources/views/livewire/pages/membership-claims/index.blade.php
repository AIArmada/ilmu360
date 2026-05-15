@php
    $claims = $this->myClaims;
    $statusClass = static fn (string $status): string => match ($status) {
        'approved' => 'bg-emerald-100 text-emerald-700',
        'rejected' => 'bg-rose-100 text-rose-700',
        'cancelled' => 'bg-slate-200 text-slate-700',
        default => 'bg-amber-100 text-amber-700',
    };
    $presenter = \App\Support\Membership\MembershipClaimPresenter::class;
@endphp

@section('title', __('My Membership Claims') . ' - ' . config('app.name'))

<div class="min-h-screen bg-slate-50 py-10 pb-28">
    <div class="container mx-auto max-w-5xl px-6 lg:px-8">
        <section class="rounded-4xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
            <div class="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-600">{{ __('Membership') }}</p>
                    <h1 class="mt-3 font-heading text-3xl font-bold text-slate-900">{{ __('My Membership Claims') }}</h1>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        {{ __('Track the speaker and institution records you asked to manage, including reviewer decisions and granted roles.') }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('contributions.index') }}" wire:navigate
                        class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                        {{ __('My Contributions') }}
                    </a>
                </div>
            </div>
        </section>

        <section class="mt-8 rounded-4xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Claim History') }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ __('Pending claims stay here until a moderator reviews them. Approved claims show the granted role.') }}</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $claims->count() }}</span>
            </div>

            <div class="mt-6 space-y-4">
                @forelse($claims as $claim)
                    <article class="rounded-2xl border border-slate-200 p-5">
                        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass((string) $claim->status->value) }}">
                                        {{ $presenter::labelForStatus($claim->status) }}
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                        {{ $presenter::labelForSubject($claim->subject_type) }}
                                    </span>
                                </div>

                                <div class="space-y-1">
                                    @if($presenter::subjectPublicUrl($claim))
                                        <a href="{{ $presenter::subjectPublicUrl($claim) }}" class="text-base font-semibold text-slate-900 hover:text-emerald-700 hover:underline">
                                            {{ $presenter::subjectTitle($claim) }}
                                        </a>
                                    @else
                                        <p class="text-base font-semibold text-slate-900">{{ $presenter::subjectTitle($claim) }}</p>
                                    @endif

                                    <p class="text-sm text-slate-600">{{ __('Submitted :date', ['date' => $claim->created_at?->diffForHumans()]) }}</p>
                                </div>

                                <p class="text-sm leading-6 text-slate-700">{{ $claim->justification }}</p>

                                @if($claim->status === \App\Enums\MembershipClaimStatus::Approved && filled($claim->granted_role_slug))
                                    <p class="text-sm font-medium text-emerald-700">{{ __('Granted role: :role', ['role' => $presenter::roleLabel($claim)]) }}</p>
                                @endif

                                @if(filled($claim->reviewer_note))
                                    <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">{{ $claim->reviewer_note }}</p>
                                @endif
                            </div>

                            @if($claim->status === \App\Enums\MembershipClaimStatus::Pending)
                                <button type="button" wire:click="cancel('{{ $claim->id }}')"
                                    class="inline-flex items-center justify-center rounded-xl border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50">
                                    {{ __('Cancel') }}
                                </button>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-12 text-center">
                        <p class="text-base font-semibold text-slate-700">{{ __('No membership claims yet.') }}</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
