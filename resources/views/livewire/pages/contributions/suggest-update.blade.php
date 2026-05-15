@php
    $latestPendingRequest = $this->latestPendingRequest;
    $canDirectEdit = $this->canDirectEdit;
@endphp

@section('title', ($canDirectEdit ? __('Update Record') : __('Suggest Update')) . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

<div class="bg-white pb-0">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-2 px-4 py-2 sm:gap-3 sm:px-6 sm:py-4 lg:px-8 lg:py-6">
        @if($latestPendingRequest)
            <section class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 shadow-sm sm:rounded-3xl sm:px-5 sm:py-5">
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-amber-700">{{ __('Pending Request') }}</p>
                <p class="mt-2 text-sm leading-6 text-amber-900">
                    {{ __('You already have a pending update request for this record from :date.', ['date' => $latestPendingRequest->created_at?->diffForHumans()]) }}
                </p>
            </section>
        @endif

        <header class="space-y-2 sm:space-y-3">
            <h1 class="font-heading text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl lg:text-5xl">
                {{ $canDirectEdit ? __('Apply an Update') : __('Suggest an Update') }}
            </h1>
        </header>

        <form wire:submit="submit" class="space-y-2 sm:space-y-3">
            {{ $this->form }}

            @error('data')
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>
            @enderror

            <div class="flex flex-col gap-3 pt-0 sm:flex-row sm:items-center">
                <button type="submit"
                    class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-emerald-700 sm:w-auto">
                    {{ $canDirectEdit ? __('Save Changes') : __('Submit Update Request') }}
                </button>
            </div>
        </form>

        <x-filament-actions::modals />
    </div>
</div>
