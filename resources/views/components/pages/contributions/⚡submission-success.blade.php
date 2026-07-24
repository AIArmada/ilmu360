<?php

use Livewire\Component;

new class extends Component
{
    public string $subjectType = 'institution';

    public ?string $submissionName = null;

    public function mount(string $subjectType): void
    {
        $this->subjectType = match ($subjectType) {
            'institusi', 'institution' => 'institution',
            'penceramah', 'person' => 'person',
            default => abort(404),
        };

        $this->submissionName = session('contribution_submission_name');
    }

    public function pageTitle(): string
    {
        return __('Submission Received');
    }

    public function heading(): string
    {
        return match ($this->subjectType) {
            'institution' => __('Thank you for submitting a new institution.'),
            'person' => __('Thank you for submitting a new speaker.'),
            default => __('Submission Received'),
        };
    }

    public function exploreTitle(): string
    {
        return match ($this->subjectType) {
            'institution' => __('Explore Institutions'),
            'person' => __('Explore Speakers'),
            default => __('Browse'),
        };
    }

    public function exploreDescription(): string
    {
        return match ($this->subjectType) {
            'institution' => __('Browse the institution directory and discover places of learning, worship, and community.'),
            'person' => __('Browse the speaker directory and discover teachers, scholars, and contributors across the country.'),
            default => __('Keep exploring the ilmu360° directory while your submission is being reviewed.'),
        };
    }

    public function exploreRoute(): string
    {
        return match ($this->subjectType) {
            'institution' => route('institutions.index'),
            'person' => route('persons.index'),
            default => route('home'),
        };
    }
};
?>

@section('title', $this->pageTitle() . ' - ' . config('app.name'))

<div class="min-h-screen bg-slate-50 py-10 pb-28">
    <div class="container mx-auto max-w-6xl px-6 lg:px-8">
        <div class="mx-auto max-w-5xl space-y-8">
            <section class="rounded-4xl border border-slate-200 bg-white px-6 py-10 text-center shadow-sm md:px-10 md:py-12">
                <div class="inline-flex size-20 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <svg class="size-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>

                <p class="mt-6 text-xs font-bold uppercase tracking-[0.22em] text-emerald-600">{{ __('Community Contribution') }}</p>
                <h1 class="mt-3 font-heading text-3xl font-bold text-slate-900 md:text-4xl">{{ $this->heading() }}</h1>

                @if (filled($this->submissionName))
                    <p class="mt-4 text-lg font-semibold text-slate-700">&ldquo;{{ $this->submissionName }}&rdquo;</p>
                @endif

                <p class="mx-auto mt-4 max-w-2xl text-base leading-7 text-slate-600">{{ __('Jejaki sumbangan anda dan statusnya.') }}</p>
            </section>

            <section class="rounded-4xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
                <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('While you wait, keep exploring') }}</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                    {{ __('Meanwhile, you can keep discovering records and activity across the ilmu360° community.') }}
                </p>

                <div class="mt-6 grid gap-4 md:grid-cols-3">
                    <a href="{{ $this->exploreRoute() }}" wire:navigate class="rounded-3xl border border-slate-200 bg-slate-50 p-5 text-left transition hover:border-emerald-300 hover:bg-emerald-50/60">
                        <p class="text-sm font-semibold text-slate-900">{{ $this->exploreTitle() }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $this->exploreDescription() }}</p>
                    </a>

                    <a href="{{ route('events.index') }}" wire:navigate class="rounded-3xl border border-slate-200 bg-slate-50 p-5 text-left transition hover:border-emerald-300 hover:bg-emerald-50/60">
                        <p class="text-sm font-semibold text-slate-900">{{ __('Browse Events') }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('See what is happening in the community while your submission is being reviewed.') }}</p>
                    </a>

                    <a href="{{ route('contributions.index') }}" wire:navigate class="rounded-3xl border border-slate-200 bg-slate-50 p-5 text-left transition hover:border-emerald-300 hover:bg-emerald-50/60">
                        <p class="text-sm font-semibold text-slate-900">{{ __('My Contributions') }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Jejaki sumbangan anda dan statusnya.') }}</p>
                    </a>
                </div>
            </section>
        </div>
    </div>
</div>