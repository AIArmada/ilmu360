@php
    use App\Support\Timezone\UserDateTimeFormatter;
@endphp

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-10 sm:px-6 lg:px-8">
    <div class="space-y-2">
        <p class="text-sm font-medium uppercase tracking-[0.2em] text-slate-500">{{ __('Member Invitation') }}</p>
        <h1 class="text-3xl font-semibold tracking-tight text-slate-950">{{ $subjectName }}</h1>
        <p class="max-w-2xl text-sm leading-6 text-slate-600">
            {{ __('You have been invited to join this :subject as :role.', ['subject' => strtolower($subjectPresentation['subject_label']), 'role' => $roleLabel]) }}
        </p>
    </div>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-medium text-slate-500">{{ __('Subject') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ $subjectName }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-slate-500">{{ __('Role') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ $roleLabel }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-slate-500">{{ __('Invited email') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ $invitation->email }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-slate-500">{{ __('Invited by') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ $invitation->inviter?->name ?? '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-sm font-medium text-slate-500">{{ __('Expires') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">
                    @if ($invitation->expires_at)
                        {{ UserDateTimeFormatter::translatedFormat($invitation->expires_at, 'l, j F Y') }}
                        {{ UserDateTimeFormatter::format($invitation->expires_at, 'h:i A') }}
                    @else
                        {{ __('No expiry') }}
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    @if (session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($this->acceptanceError)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ $this->acceptanceError }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        @if ($this->canAccept)
            <button
                type="button"
                wire:click="accept"
                class="inline-flex items-center justify-center rounded-full bg-slate-950 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800"
            >
                {{ __('Accept invitation') }}
            </button>
        @endif

        <a
            href="{{ $subjectPresentation['redirect_url'] }}"
            class="inline-flex items-center justify-center rounded-full border border-slate-300 px-5 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:text-slate-950"
        >
            {{ __('View :subject', ['subject' => $subjectPresentation['subject_label']]) }}
        </a>
    </div>
</div>
